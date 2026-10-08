<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;
use App\Models\Field;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Services\Catalogue\ApplicantProgrammes;
use App\Services\Catalogue\CatalogueMentions;
use App\Services\Catalogue\LegacyFieldMap;
use App\Services\Catalogue\ListingScopes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Whether an applicant's programme and institution fall inside what a listing is open to.
 *
 * What a listing is open to comes from two places, and only ever one of them:
 *   1. what the provider chose ("Open to" rows), or
 *   2. when they chose nothing and set no older field of study, what the title and description
 *      name (CatalogueMentions). A structured choice always wins over the words.
 *
 * The rows form two groups. PROGRAMMES and FIELDS are one: the applicant fits if any programme of
 * theirs is a listed programme or lies inside a listed field. INSTITUTIONS are the other: they fit
 * if they study at, or have applied to, a listed one. When both exist, both must fit.
 *
 * Nothing is loosened beyond what was chosen, and nothing is guessed:
 *   - a programme still waiting for an administrator is never an exact match. As the applicant's, it
 *     counts through its field only; named in a listing, it means its field until it is approved;
 *   - what an applicant has not said is "needs information", not a failure;
 *   - a Primary pupil has no programme and no institution, so a listing for specific programmes can
 *     never fit them: that one is a failure, since no answer they add could change it;
 *   - an older free-text field of study stands in for a FIELD choice (never for a named programme)
 *     until the applicant picks a programme.
 */
class ProgrammeScope
{
    /** @var \WeakMap<ApplicantProfile, array> what was read about each applicant, so a list of listings asks once */
    private \WeakMap $applicants;

    public function __construct(private readonly CatalogueMentions $mentions)
    {
        $this->applicants = new \WeakMap();
    }

    /**
     * @return array<int, RequirementOutcome> none, one or two outcomes
     */
    public function evaluate(ApplicantProfile $profile, Opportunity $listing): array
    {
        try {
            return $this->outcomes($profile, $listing);
        } catch (QueryException) {
            // No catalogue to ask (a fresh install before the tables exist, or a test with no database):
            // there is then no programme rule to apply, and nothing else is affected.
            return [];
        }
    }

    /** @return array<int, RequirementOutcome> */
    private function outcomes(ApplicantProfile $profile, Opportunity $listing): array
    {
        [$scopes, $origin] = $this->scopeOf($listing, $profile);

        if ($scopes->isEmpty()) {
            return [];
        }

        $applicant = $this->applicant($profile);
        $describer = app(ListingScopes::class);
        $outcomes = [];

        $what = $scopes->filter(fn (OpportunityScope $s) => $s->programme_id !== null || $s->field_id !== null)->values();
        $where = $scopes->filter(fn (OpportunityScope $s) => $s->institution_id !== null)->values();

        if ($what->isNotEmpty()) {
            $outcomes[] = $this->programmeOutcome($what, $applicant, $describer->describeProgrammes($what), $origin);
        }

        if ($where->isNotEmpty()) {
            $outcomes[] = $this->institutionOutcome($where, $applicant, $describer->describeInstitutions($where), $origin);
        }

        return $outcomes;
    }

    /** Whether this applicant has chosen at least one catalogue programme. */
    public function hasProgrammes(ApplicantProfile $profile): bool
    {
        try {
            return $this->applicant($profile)['programmes']->isNotEmpty();
        } catch (QueryException) {
            return false;
        }
    }

    /** Whether the listing has "Open to" rows of its own - the provider's choice, which the older field rules defer to. */
    public static function hasChosenScope(Opportunity $listing): bool
    {
        if ($listing->relationLoaded('scopes')) {
            return $listing->scopes->isNotEmpty();
        }

        try {
            return $listing->exists && $listing->scopes()->exists();
        } catch (QueryException) {
            return false;
        }
    }

    // ----------------------------------------------------------- the listing --

    /**
     * @return array{0: Collection<int, OpportunityScope>, 1: ?array{source: string, phrase: string}}
     */
    private function scopeOf(Opportunity $listing, ApplicantProfile $profile): array
    {
        if (self::hasChosenScope($listing)) {
            $scopes = $listing->relationLoaded('scopes')
                ? $listing->scopes
                : $listing->scopes()->with(['programme.field.parent', 'field.parent', 'institution'])->get();

            return [$scopes, null];
        }

        // The words. Programmes named in them always apply; fields named in them apply here only for
        // an applicant who has chosen a programme - for anyone else the older field rule reads them.
        $rows = collect();
        $origin = null;
        $withFields = $this->applicant($profile)['programmes']->isNotEmpty();

        foreach ($this->mentions->read($listing) as $mention) {
            if ($mention['type'] === 'field' && ! $withFields) {
                continue;
            }

            $row = new OpportunityScope($mention['type'] === 'programme' ? ['programme_id' => $mention['id']] : ['field_id' => $mention['id']]);

            if ($mention['type'] === 'programme') {
                $row->setRelation('programme', $this->mentions->programme($mention['id']));
            } else {
                $row->setRelation('field', $this->mentions->field($mention['id']));
            }

            $rows->push($row);
            $origin ??= ['source' => $mention['source'], 'phrase' => $mention['phrase']];
        }

        return [$rows, $origin];
    }

    // ---------------------------------------------------------- the applicant --

    /**
     * @return array{mode: string, programmes: Collection<int, Programme>, legacy: Collection<int, Field>, institutions: array<int, int>, institutionNames: array<int, string>}
     */
    private function applicant(ApplicantProfile $profile): array
    {
        return $this->applicants[$profile] ??= $this->readApplicant($profile);
    }

    /** @return array{mode: string, programmes: Collection<int, Programme>, legacy: Collection<int, Field>, institutions: array<int, int>, institutionNames: array<int, string>} */
    private function readApplicant(ApplicantProfile $profile): array
    {
        $mode = ApplicantProgrammes::modeFor($profile);
        $programmes = collect();
        $institutions = [];
        $names = [];

        if ($profile->exists) {
            $choices = $profile->relationLoaded('programmeChoices')
                ? $profile->programmeChoices
                : $profile->programmeChoices()->with(['programme.field.parent', 'institution'])->get();

            $programmes = $choices->map(fn ($c) => $c->programme)->filter()->values();

            if ($mode === ApplicantProgrammes::CURRENT) {
                $current = $choices->firstWhere('kind', 'current');
                $institutions = $current?->institution_id ? [(int) $current->institution_id] : [];
                $names = $current?->institution ? [(int) $current->institution_id => $current->institution->name] : [];
            } elseif ($mode === ApplicantProgrammes::INTENDED) {
                $applied = $profile->relationLoaded('appliedInstitutions')
                    ? $profile->appliedInstitutions
                    : $profile->appliedInstitutions()->with('institution')->get();

                $institutions = $applied->pluck('institution_id')->map(fn ($i) => (int) $i)->all();
                $names = $applied->mapWithKeys(fn ($a) => [(int) $a->institution_id => $a->institution?->name ?? ''])->all();
            }
        }

        // An older free-text field of study, only until a programme is chosen.
        $legacy = collect();

        if ($programmes->isEmpty() && $mode === ApplicantProgrammes::CURRENT && filled($profile->field_of_study)) {
            $codes = LegacyFieldMap::codesFor($profile->field_of_study);
            $legacy = $codes === [] ? collect() : $this->mentions->fieldsByCode($codes);
        }

        return ['mode' => $mode, 'programmes' => $programmes, 'legacy' => $legacy, 'institutions' => $institutions, 'institutionNames' => $names];
    }

    // ------------------------------------------------------ programmes and fields --

    private function programmeOutcome(Collection $what, array $applicant, string $open, ?array $origin): RequirementOutcome
    {
        $prefix = $this->prefix($origin);

        // Listed programmes: an approved one is itself; a pending one is only its field (see the class note).
        $programmeIds = [];
        $fields = collect();

        foreach ($what as $row) {
            if ($row->programme_id !== null) {
                $programme = $row->programme ?? Programme::with('field.parent')->find($row->programme_id);

                if ($programme === null) {
                    continue;
                }

                if ($programme->status === Programme::APPROVED) {
                    $programmeIds[] = $programme->id;
                } elseif ($programme->field) {
                    $fields->push($programme->field);
                }
            } else {
                $fields->push($row->field ?? Field::with('parent')->find($row->field_id));
            }
        }

        $fields = $fields->filter()->unique('id');
        $best = null;
        $matched = null;

        foreach ($applicant['programmes'] as $programme) {
            $rank = $this->rankProgramme($programme, $programmeIds, $fields);

            if ($rank !== null && ($best === null || $rank < $best)) {
                $best = $rank;
                $matched = $programme->name;
            }
        }

        $legacyMatch = false;

        if ($best === null) {
            foreach ($applicant['legacy'] as $field) {
                $rank = $this->rankField($field, $fields);

                if ($rank !== null && ($best === null || $rank < $best)) {
                    $best = $rank;
                    $matched = $field->label();
                    $legacyMatch = true;
                }
            }
        }

        if ($best !== null) {
            return RequirementOutcome::pass(
                RequirementOutcome::TYPE_PROGRAMME_SCOPE,
                $prefix . 'Programme: open to ' . $open . '; ' . ($legacyMatch ? 'your field, ' : 'your programme, ') . $matched . ', fits.',
                required: $open,
                actual: $matched,
                fit: [RequirementOutcome::FIT_PROGRAMME, RequirementOutcome::FIT_NARROW_FIELD, RequirementOutcome::FIT_BROAD_FIELD][$best],
            );
        }

        $have = $applicant['programmes']->isNotEmpty() || $applicant['legacy']->isNotEmpty();
        $hasProgrammeRows = $programmeIds !== [];

        // Only an older field to go on, and the listing names programmes: a field cannot say which one.
        $onlyLegacy = $applicant['programmes']->isEmpty() && $applicant['legacy']->isNotEmpty();

        if ($have && ! ($onlyLegacy && $hasProgrammeRows)) {
            $yours = $applicant['programmes']->isNotEmpty()
                ? ($applicant['programmes']->count() === 1 ? 'your programme is ' : 'your programmes are ') . $applicant['programmes']->pluck('name')->implode(', ')
                : 'your field is ' . $applicant['legacy']->map->label()->implode(', ');

            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_PROGRAMME_SCOPE,
                $prefix . 'Programme: this scholarship is open to ' . $open . ', but ' . $yours . '.',
                required: $open,
                actual: $applicant['programmes']->pluck('name')->implode(', ') ?: null,
            );
        }

        if ($applicant['mode'] === ApplicantProgrammes::NONE) {
            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_PROGRAMME_SCOPE,
                $prefix . 'Programme: this scholarship is open to ' . $open . ', which is for students on a programme. Your current level has none.',
                required: $open,
            );
        }

        return RequirementOutcome::needsInfo(
            RequirementOutcome::TYPE_PROGRAMME_SCOPE,
            $prefix . 'Programme: open to ' . $open . ' - add your programme to your profile so we can check it.',
            ScholarFitFieldNames::PROGRAMME,
            required: $open,
        );
    }

    /** 0 = the programme itself, 1 = a narrow field it is in, 2 = a broad field it is in; null = none. */
    private function rankProgramme(Programme $programme, array $programmeIds, Collection $fields): ?int
    {
        if ($programme->status === Programme::APPROVED && in_array($programme->id, $programmeIds, true)) {
            return 0;
        }

        $field = $programme->field;

        return $field === null ? null : $this->rankField($field, $fields);
    }

    private function rankField(Field $field, Collection $listed): ?int
    {
        $best = null;

        foreach ($listed as $wanted) {
            $rank = match (true) {
                $wanted->id === $field->id => $field->isBroad() ? 2 : 1,
                $wanted->isBroad() && $field->parent_id === $wanted->id => 2,
                default => null,
            };

            if ($rank !== null && ($best === null || $rank < $best)) {
                $best = $rank;
            }
        }

        return $best;
    }

    // ------------------------------------------------------------ institutions --

    private function institutionOutcome(Collection $where, array $applicant, string $open, ?array $origin): RequirementOutcome
    {
        $prefix = $this->prefix($origin);
        $listed = $where->pluck('institution_id')->map(fn ($i) => (int) $i)->all();
        $have = $applicant['institutions'];

        if ($have === []) {
            if ($applicant['mode'] === ApplicantProgrammes::NONE) {
                return RequirementOutcome::fail(
                    RequirementOutcome::TYPE_INSTITUTION_SCOPE,
                    $prefix . 'Institution: this scholarship is for students at ' . $open . '. Your current level has no institution to check.',
                    required: $open,
                );
            }

            return RequirementOutcome::needsInfo(
                RequirementOutcome::TYPE_INSTITUTION_SCOPE,
                $prefix . 'Institution: for students at ' . $open . ' - '
                    . ($applicant['mode'] === ApplicantProgrammes::CURRENT ? 'add where you study' : 'add the institutions you have applied to or hold an offer from')
                    . ' to your profile so we can check it.',
                ScholarFitFieldNames::INSTITUTION,
                required: $open,
            );
        }

        $names = collect($applicant['institutionNames'])->filter()->implode(', ');

        if (array_intersect($have, $listed) !== []) {
            return RequirementOutcome::pass(
                RequirementOutcome::TYPE_INSTITUTION_SCOPE,
                $prefix . 'Institution: for students at ' . $open . '; ' . ($applicant['mode'] === ApplicantProgrammes::CURRENT ? 'you study at ' : 'you have applied to ') . ($names ?: 'a listed one') . '.',
                required: $open,
                actual: $names ?: null,
            );
        }

        return RequirementOutcome::fail(
            RequirementOutcome::TYPE_INSTITUTION_SCOPE,
            $prefix . 'Institution: this scholarship is for students at ' . $open . ', but '
                . ($applicant['mode'] === ApplicantProgrammes::CURRENT ? 'you study at ' : 'you have applied to ') . ($names ?: 'another institution') . '.',
            required: $open,
            actual: $names ?: null,
        );
    }

    /** "Scholarship title states " when the rule came from the words rather than a choice. */
    private function prefix(?array $origin): string
    {
        return $origin === null ? '' : 'Scholarship ' . $origin['source'] . ' states: ';
    }
}
