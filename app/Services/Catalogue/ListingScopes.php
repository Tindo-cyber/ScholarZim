<?php

namespace App\Services\Catalogue;

use App\Models\Field;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Support\EducationLevel;
use Illuminate\Support\Collection;

/**
 * What a listing is open to, as the provider chooses it and as it is stored.
 *
 * The form posts `scope_programmes[]`, `scope_fields[]` and `scope_institutions[]` (ids), and
 * optionally a `programme_suggestion` with its `programme_suggestion_field` for a programme
 * that is not listed. Stored as one OpportunityScope row per choice. No rows = open to anyone.
 *
 * Nothing here decides whether an applicant matches; it only keeps, checks and describes the
 * provider's choice.
 */
class ListingScopes
{
    public const MAX_PROGRAMMES = 60;

    public const MAX_FIELDS = 20;

    public const MAX_INSTITUTIONS = 40;

    public function __construct(private readonly ProgrammeCatalogue $catalogue)
    {
    }

    /** Whether the level uses programmes at all: Certificate and above, or no level chosen. */
    public static function appliesTo(?string $level): bool
    {
        $canonical = EducationLevel::canonical($level);

        return $canonical === null || in_array($canonical, Programme::LEVELS, true);
    }

    /** The scope keys in a submission, cleaned to unique integers; anything else is dropped. */
    public function choices(array $input): array
    {
        $ids = static fn (mixed $value): array => is_array($value)
            ? array_values(array_unique(array_map('intval', array_filter($value, fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v))))))
            : [];

        return [
            'programmes' => $ids($input['scope_programmes'] ?? []),
            'fields' => $ids($input['scope_fields'] ?? []),
            'institutions' => $ids($input['scope_institutions'] ?? []),
        ];
    }

    // -------------------------------------------------------------- validation --

    /**
     * Why a submission's choices cannot be saved, keyed by the form field. Empty when fine.
     *
     * @return array<string, string>
     */
    public function problems(array $input, ?string $level, User $provider): array
    {
        $problems = [];
        $choices = $this->choices($input);
        $any = $choices['programmes'] || $choices['fields'] || $choices['institutions'] || filled($input['programme_suggestion'] ?? null);
        $canonical = EducationLevel::canonical($level);

        if ($any && ! self::appliesTo($level)) {
            $key = $choices['programmes'] ? 'scope_programmes' : ($choices['fields'] ? 'scope_fields' : ($choices['institutions'] ? 'scope_institutions' : 'programme_suggestion'));

            return [$key => 'Programmes, fields and institutions only apply to Certificate, Diploma, Undergraduate and higher awards. '
                . EducationLevel::label($level) . ' awards are for a school level, not a programme.'];
        }

        foreach ([['programmes', self::MAX_PROGRAMMES, 'programmes'], ['fields', self::MAX_FIELDS, 'fields'], ['institutions', self::MAX_INSTITUTIONS, 'institutions']] as [$key, $max, $word]) {
            if (count($choices[$key]) > $max) {
                $problems['scope_' . $key] = 'Choose at most ' . $max . ' ' . $word . '. For a wider audience choose a field instead.';
            }
        }

        $raw = fn (string $key) => is_array($input[$key] ?? null) ? array_values($input[$key]) : [];

        $malformed = [];

        foreach (['scope_programmes' => 'a programme', 'scope_fields' => 'a field', 'scope_institutions' => 'an institution'] as $key => $what) {
            foreach ($raw($key) as $i => $value) {
                if (! (is_int($value) || (is_string($value) && ctype_digit($value)))) {
                    $malformed["$key.$i"] = 'That is not ' . $what . '.';
                }
            }
        }

        // Report the malformed values first; the rest would only be noise on top of them.
        if ($malformed !== []) {
            return $malformed;
        }

        $known = Programme::whereIn('id', $choices['programmes'])->get()->keyBy('id');

        foreach (array_values($choices['programmes']) as $i => $id) {
            $programme = $known->get($id);

            if ($programme === null || ! $this->usableBy($programme, $provider)) {
                $problems["scope_programmes.$i"] = 'That programme is not in the catalogue.';
            } elseif ($canonical !== null && $programme->education_level !== $canonical) {
                $problems['scope_programmes'] = $programme->name . ' is a ' . $programme->levelLabel()
                    . ' programme, but this award is for ' . EducationLevel::label($canonical) . ' students. Choose programmes at that level.';
            }
        }

        $fields = Field::whereIn('id', $choices['fields'])->pluck('id')->all();

        foreach (array_values($choices['fields']) as $i => $id) {
            if (! in_array($id, $fields, true)) {
                $problems["scope_fields.$i"] = 'That field does not exist.';
            }
        }

        $institutions = Institution::active()->whereIn('id', $choices['institutions'])->pluck('id')->all();

        if (array_diff($choices['institutions'], $institutions) !== []) {
            $problems['scope_institutions'] = 'One of the institutions chosen is not available. Pick from the list.';
        }

        if (filled($input['programme_suggestion'] ?? null)) {
            if ($canonical === null) {
                $problems['programme_suggestion'] = 'Choose the level of study first - a programme you add belongs to a level.';
            } elseif (! Field::whereKey((int) ($input['programme_suggestion_field'] ?? 0))->exists()) {
                $problems['programme_suggestion_field'] = 'Choose the field the new programme belongs to.';
            } elseif (mb_strlen(trim((string) $input['programme_suggestion'])) < 3 || mb_strlen((string) $input['programme_suggestion']) > 200) {
                $problems['programme_suggestion'] = 'Give the programme\'s name (3 to 200 characters).';
            }
        }

        return $problems;
    }

    /** In the catalogue, or pending and suggested by this very person. */
    private function usableBy(Programme $programme, User $provider): bool
    {
        return ($programme->status === Programme::APPROVED && $programme->is_active)
            || ($programme->isPending() && $programme->suggested_by === $provider->user_id);
    }

    // ------------------------------------------------------------------ saving --

    /**
     * Replace the listing's scope with what the submission chose. Checked beforehand by
     * problems(); anything that no longer holds is simply not saved.
     */
    public function save(Opportunity $listing, array $input, User $provider): void
    {
        $level = EducationLevel::canonical($listing->education_level);
        $programmes = $this->choices($input)['programmes'];
        $fields = $this->choices($input)['fields'];
        $institutions = $this->choices($input)['institutions'];

        if (! self::appliesTo($level)) {
            $programmes = $fields = $institutions = [];
        } elseif (filled($input['programme_suggestion'] ?? null) && $level !== null) {
            try {
                $programmes[] = $this->catalogue->suggest($provider, (string) $input['programme_suggestion'], $level, (int) ($input['programme_suggestion_field'] ?? 0))->id;
            } catch (\InvalidArgumentException|\DomainException) {
                // problems() has already reported anything the person can fix.
            }
        }

        $listing->scopes()->delete();

        foreach (array_unique($programmes) as $id) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'programme_id' => $id]);
        }

        foreach (array_unique($fields) as $id) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'field_id' => $id]);
        }

        foreach (array_unique($institutions) as $id) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'institution_id' => $id]);
        }
    }

    /**
     * Whether the submission would change who the listing is open to. A new suggestion counts.
     */
    public function changed(Opportunity $listing, array $input): bool
    {
        $choices = $this->choices($input);

        if (filled($input['programme_suggestion'] ?? null)) {
            return true;
        }

        $current = [
            'programmes' => $listing->scopes()->whereNotNull('programme_id')->pluck('programme_id')->map(fn ($i) => (int) $i)->all(),
            'fields' => $listing->scopes()->whereNotNull('field_id')->pluck('field_id')->map(fn ($i) => (int) $i)->all(),
            'institutions' => $listing->scopes()->whereNotNull('institution_id')->pluck('institution_id')->map(fn ($i) => (int) $i)->all(),
        ];

        foreach ($choices as $key => $ids) {
            sort($ids);
            sort($current[$key]);

            if ($ids !== $current[$key]) {
                return true;
            }
        }

        return false;
    }

    // ----------------------------------------------------------------- reading --

    /**
     * The scope in plain words, after "Open to: ".
     *
     * @param  Collection<int, OpportunityScope>  $scopes
     */
    public function describe(Collection $scopes): string
    {
        $programmes = $scopes->pluck('programme')->filter()->map->name->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
        $fields = $scopes->pluck('field')->filter()->sortBy('code')->values();
        $institutions = $scopes->pluck('institution')->filter()->map(fn (Institution $i) => $this->short($i))->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();

        $what = [];

        if ($programmes->isNotEmpty()) {
            $what[] = $this->join($programmes->all());
        }

        if ($fields->isNotEmpty()) {
            $what[] = $this->join($fields->map(fn (Field $f) => $f->isBroad()
                ? 'any programme in ' . $f->label()
                : 'any ' . $f->label() . ' programme')->all());
        }

        $text = $what === [] ? 'any programme' : $this->join($what);

        // "any programme in X or any programme in Y" reads better as written; with several fields the
        // join above already gives "any X programme or any Y programme".
        return $institutions->isEmpty() ? $text : $text . ' at ' . $this->join($institutions->all());
    }

    /** "MSU" for a short code, the full name when the code is a hyphenated label. */
    private function short(Institution $institution): string
    {
        return preg_match('/^[A-Z]{2,6}$/', $institution->code) ? $institution->code : $institution->name;
    }

    /** @param  array<int, string>  $items */
    private function join(array $items): string
    {
        return count($items) <= 1 ? ($items[0] ?? '') : implode(', ', array_slice($items, 0, -1)) . ' or ' . end($items);
    }
}
