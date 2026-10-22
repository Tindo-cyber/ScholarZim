<?php

namespace App\Services\Catalogue;

use App\Models\Field;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\FormOptions;
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

    /**
     * The form's "Open to" answer, one of four:
     *  - OPEN_ALL        every programme at the level;
     *  - OPEN_PROGRAMMES only the specific programmes ticked (a programme never opens its whole field);
     *  - OPEN_FIELDS     every programme in the field or fields ticked;
     *  - OPEN_CHOSEN     programmes and fields together. The form only offers it for a listing already saved that way,
     *                    and a direct request may still send it.
     */
    public const OPEN_CHOSEN = 'chosen';

    public const OPEN_ALL = 'all';

    public const OPEN_PROGRAMMES = 'programmes';

    public const OPEN_FIELDS = 'fields';

    /** Every value the form's "Open to" answer may take. */
    public const OPEN_ANSWERS = [self::OPEN_CHOSEN, self::OPEN_ALL, self::OPEN_PROGRAMMES, self::OPEN_FIELDS];

    /** The form's institutions answer: specific institutions, or any. */
    public const INSTITUTIONS_CHOSEN = 'chosen';

    public const INSTITUTIONS_ANY = 'any';

    /** "Also check yourself" conditions: how many, and how long each may be. */
    public const MAX_OTHER_CONDITIONS = 10;

    public const MAX_OTHER_CONDITION_LENGTH = 200;

    public function __construct(private readonly ProgrammeCatalogue $catalogue)
    {
    }

    // ---------------------------------------------------------- the decision --

    /**
     * Whether the listing funds study outside Zimbabwe: another country, "Any country" or online.
     *
     * The catalogue holds Zimbabwean programmes and institutions only, so an award for study abroad cannot
     * name them. It is open to fields instead (fields are the international ISCED codes and mean the same
     * everywhere), or to all programmes, and names its host universities in words that are shown, not checked.
     * A blank country is Zimbabwe, as everywhere else in the application.
     */
    public static function isAbroad(?string $country): bool
    {
        $country = trim((string) $country);

        return $country !== '' && $country !== FormOptions::DEFAULT_COUNTRY;
    }

    /**
     * Whether a listing at this level must say who it is open to: Certificate and above. Below that there is
     * no programme to choose, and a listing with no level at all is not asked (it is not tied to one).
     */
    public static function requiresDecision(?string $level): bool
    {
        return in_array(EducationLevel::canonical($level), Programme::LEVELS, true);
    }

    /**
     * The provider's answer, as the listing stores it.
     *
     * A list of programmes or fields is itself the answer "only these", so the radio button is not needed when
     * one is ticked - it only has to be chosen to say "all programmes", or "any institution". Nothing chosen
     * and no answer given stays null: not decided, which validation refuses for a new listing at a programme
     * level and which a listing posted before this rule simply is.
     *
     * @return array{open_to_all_programmes: ?bool, any_institution: ?bool, accepts_o_level: bool, host_institutions: ?string, other_conditions: ?string}
     */
    public function decision(array $input, ?string $level, ?string $country): array
    {
        $abroad = self::isAbroad($country);
        $input = $this->answered($input);
        $choices = $this->choices($input);
        $what = $choices['fields'] !== [] || (! $abroad && ($choices['programmes'] !== [] || filled($input['programme_suggestion'] ?? null)));

        $open = $input['open_to'] ?? null;
        $institutions = $input['institutions_open_to'] ?? null;

        $uses = self::appliesTo($level);

        return [
            'open_to_all_programmes' => ! $uses ? null : match (true) {
                $open === self::OPEN_ALL => true,
                in_array($open, [self::OPEN_CHOSEN, self::OPEN_PROGRAMMES, self::OPEN_FIELDS], true), $what => false,
                default => null,
            },
            'any_institution' => ! $uses || $abroad ? null : match (true) {
                $institutions === self::INSTITUTIONS_ANY => true,
                $institutions === self::INSTITUTIONS_CHOSEN, $choices['institutions'] !== [] => false,
                default => null,
            },
            'accepts_o_level' => EducationLevel::canonical($level) === EducationLevel::UNDERGRADUATE
                && filter_var($input['accepts_o_level'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'host_institutions' => $abroad && $uses && filled($input['host_institutions'] ?? null)
                ? mb_substr(trim((string) $input['host_institutions']), 0, 500)
                : null,
            'other_conditions' => ($lines = self::conditionLines($input['other_conditions'] ?? null)) === [] ? null : implode("\n", $lines),
        ];
    }

    /**
     * The submission with the answer to "what is it open to?" applied to the lists: choosing "only these programmes"
     * drops any field ticked earlier, and choosing "an entire field" drops the programmes and any programme being
     * suggested. That is what keeps a list the person can no longer see from quietly changing who qualifies. The
     * older answers ("all", "chosen", or none) leave the lists as they were.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function answered(array $input): array
    {
        $open = $input['open_to'] ?? null;

        if ($open === self::OPEN_PROGRAMMES) {
            $input['scope_fields'] = [];
        } elseif ($open === self::OPEN_FIELDS) {
            $input['scope_programmes'] = [];
            $input['programme_suggestion'] = null;
            $input['programme_suggestion_field'] = null;
        }

        return $input;
    }

    /**
     * "Also check yourself" conditions, one a line, blanks and repeats dropped.
     *
     * @return array<int, string>
     */
    public static function conditionLines(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $lines = array_map(static fn (string $line) => trim(preg_replace('/^[\s\-\*\x{2022}]+/u', '', $line) ?? ''), preg_split('/\R/u', $value) ?: []);

        return array_values(array_unique(array_filter($lines, static fn (string $line) => $line !== '')));
    }

    /**
     * Why the provider's answer to "who is this open to?" cannot be saved, keyed by the form field.
     *
     * Checked when a listing is submitted, never for a draft - a draft may be unfinished.
     *
     * @return array<string, string>
     */
    public function decisionProblems(array $input, ?string $level, ?string $country): array
    {
        $problems = [];
        $input = $this->answered($input);
        $choices = $this->choices($input);

        if (count(self::conditionLines($input['other_conditions'] ?? null)) > self::MAX_OTHER_CONDITIONS) {
            $problems['other_conditions'] = 'List at most ' . self::MAX_OTHER_CONDITIONS . ' conditions, one a line.';
        } elseif (collect(self::conditionLines($input['other_conditions'] ?? null))->contains(fn ($l) => mb_strlen($l) > self::MAX_OTHER_CONDITION_LENGTH)) {
            $problems['other_conditions'] = 'Keep each condition to ' . self::MAX_OTHER_CONDITION_LENGTH . ' characters or fewer.';
        }

        if (! self::appliesTo($level)) {
            return $problems;
        }

        if (self::isAbroad($country)) {
            if (($input['open_to'] ?? null) === self::OPEN_PROGRAMMES) {
                $problems['open_to'] = 'This scholarship is for study outside Zimbabwe, and the programme list holds Zimbabwean programmes only. '
                    . 'Choose "An entire field of study" and pick the fields it is open to (for example ICT), or "All programmes at this level".';
            }

            if ($choices['programmes'] !== [] || filled($input['programme_suggestion'] ?? null)) {
                $problems['scope_programmes'] = 'This scholarship is for study outside Zimbabwe, and the programme list holds Zimbabwean programmes only. '
                    . 'Choose the fields it is open to instead (for example ICT), or "All programmes at this level".';
            }

            if ($choices['institutions'] !== []) {
                $problems['scope_institutions'] = 'The institution list holds Zimbabwean institutions only. Write the universities abroad under "Host universities" instead.';
            }
        }

        if (! self::requiresDecision($level)) {
            return $problems;
        }

        $decision = $this->decision($input, $level, $country);
        $what = $choices['fields'] !== [] || (! self::isAbroad($country) && ($choices['programmes'] !== [] || filled($input['programme_suggestion'] ?? null)));

        if ($decision['open_to_all_programmes'] === null) {
            $problems['open_to'] = 'Say who this scholarship is open to: choose the programmes or fields it is for, or choose "All programmes at this level". '
                . 'ScholarFit uses this to tell students whether they qualify.';
        } elseif ($decision['open_to_all_programmes'] === false && ! $what) {
            $problems['open_to'] = match (true) {
                self::isAbroad($country) => 'Choose at least one field this scholarship is open to, or choose "All programmes at this level".',
                ($input['open_to'] ?? null) === self::OPEN_PROGRAMMES => 'Choose at least one programme, or choose a different way of saying who it is open to.',
                ($input['open_to'] ?? null) === self::OPEN_FIELDS => 'Choose at least one field of study, or choose a different way of saying who it is open to.',
                default => 'Choose at least one programme or field, or choose "All programmes at this level".',
            };
        }

        if (! self::isAbroad($country)) {
            if ($decision['any_institution'] === null) {
                $problems['institutions_open_to'] = 'Say where students must study: choose the institutions this scholarship pays for, or choose "Any institution".';
            } elseif ($decision['any_institution'] === false && $choices['institutions'] === []) {
                $problems['institutions_open_to'] = 'Choose at least one institution, or choose "Any institution".';
            }
        }

        return $problems;
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
        $input = $this->answered($input);
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
        $input = $this->answered($input);
        $programmes = $this->choices($input)['programmes'];
        $fields = $this->choices($input)['fields'];
        $institutions = $this->choices($input)['institutions'];

        $abroad = self::isAbroad($listing->country);
        $decision = $this->decision($input, $level, $listing->country);

        // An answer of "all programmes" or "any institution" is the rule; a list left behind beside it is not.
        // Abroad there is no Zimbabwean programme or institution to name - only fields.
        if ($decision['open_to_all_programmes'] === true) {
            $programmes = $fields = [];
        }

        if ($decision['any_institution'] === true || $abroad) {
            $institutions = [];
        }

        if ($abroad) {
            $programmes = [];
        }

        if (! self::appliesTo($level)) {
            $programmes = $fields = $institutions = [];
        } elseif (filled($input['programme_suggestion'] ?? null) && $level !== null && ! $abroad && $decision['open_to_all_programmes'] !== true) {
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
        $text = $this->describeProgrammes($scopes) ?? 'any programme';
        $where = $this->describeInstitutions($scopes);

        return $where === null ? $text : $text . ' at ' . $where;
    }

    /**
     * The programmes and fields alone ("BSc X or any Engineering programme"), or null when the
     * listing names none.
     *
     * @param  Collection<int, OpportunityScope>  $scopes
     */
    public function describeProgrammes(Collection $scopes): ?string
    {
        $programmes = $scopes->pluck('programme')->filter()->map->name->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
        $fields = $scopes->pluck('field')->filter()->sortBy('code')->values();

        $what = [];

        if ($programmes->isNotEmpty()) {
            $what[] = $this->join($programmes->all());
        }

        if ($fields->isNotEmpty()) {
            $what[] = $this->join($fields->map(fn (Field $f) => $f->isBroad()
                ? 'any programme in ' . $f->label()
                : 'any ' . $f->label() . ' programme')->all());
        }

        return $what === [] ? null : $this->join($what);
    }

    /**
     * The fields a listing is about, for reports: the field of every programme it names and every field it is open to,
     * each once. A listing with no programme or field rows falls back to the free-text field it was posted with (those
     * posted before the catalogue), and is blank when it has neither. Reading only; it never widens who qualifies.
     *
     * @param  Collection<int, OpportunityScope>  $scopes
     */
    public function fieldLabels(Collection $scopes, ?string $legacy = null): ?string
    {
        $labels = $scopes->map(fn (OpportunityScope $s) => $s->field ?? $s->programme?->field)
            ->filter()
            ->map(fn (Field $f) => $f->label())
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        if ($labels->isNotEmpty()) {
            return $labels->implode('; ');
        }

        return filled($legacy) ? trim($legacy) : null;
    }

    /**
     * The institutions alone ("MSU or NUST"), or null when the listing names none.
     *
     * @param  Collection<int, OpportunityScope>  $scopes
     */
    public function describeInstitutions(Collection $scopes): ?string
    {
        $institutions = $scopes->pluck('institution')->filter()->map(fn (Institution $i) => $this->short($i))->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();

        return $institutions->isEmpty() ? null : $this->join($institutions->all());
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
