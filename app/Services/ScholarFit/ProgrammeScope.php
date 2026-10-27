<?php

namespace App\Services\ScholarFit;

use App\Models\ApplicantProfile;
use App\Models\Field;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Services\Catalogue\ApplicantProgrammes;
use App\Services\Catalogue\CatalogueMentions;
use App\Services\Catalogue\FieldIndex;
use App\Services\Catalogue\LegacyFieldMap;
use App\Services\Catalogue\ListingScopes;
use App\Support\EducationLevel;
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
        $applicant = $this->applicant($profile);
        $outcomes = [];

        // A school-leaver's chosen programmes are what they hope to study: a preference, not something they have done.
        // A listing at another level, or in other programmes, is outside those preferences - which is said, and shown
        // after the ones inside them, but is never a reason to refuse them. Only what a student actually is (the
        // programme they are enrolled on) can fail a listing's programme restriction. See outsidePreferences().
        $level = $this->intendedLevelOutcome($listing, $applicant);

        if ($level !== null) {
            $outcomes[] = $level;
        }

        // Institutions are WHERE THE AWARD IS TO BE STUDIED, not where an applicant is now. A student about to start (an
        // A-Level leaver, say) is not at the university yet and must not be turned away for that, and a student already
        // elsewhere may be moving. So the listing's institutions - from the catalogue or typed in - are said to the
        // applicant as a note, once, and are never compared with the institution on their profile.
        $unlisted = $this->unlisted($listing);

        if ($scopes->isEmpty()) {
            if ($unlisted !== []) {
                $outcomes[] = $this->destinationNote(collect(), $unlisted);
            }

            return $outcomes;
        }

        $applicant = $this->atListingLevel($listing, $applicant);
        $describer = app(ListingScopes::class);

        $what = $scopes->filter(fn (OpportunityScope $s) => $s->programme_id !== null || $s->field_id !== null)->values();
        $where = $scopes->filter(fn (OpportunityScope $s) => $s->institution_id !== null)->values();

        // The level note has already said this listing is outside their chosen programmes; saying again that their
        // programmes are not its programmes would be the same note twice.
        if ($what->isNotEmpty() && $level === null) {
            $outcomes[] = $this->asPreference($this->programmeOutcome($what, $applicant, $describer->describeProgrammes($what), $origin), $applicant);
        }

        if ($where->isNotEmpty() || $unlisted !== []) {
            $outcomes[] = $this->destinationNote($where, $unlisted);
        }

        return $outcomes;
    }

    /**
     * Whether a field named in a listing's wording is read against the catalogue for this applicant: they have
     * chosen a programme, or they are a school-leaver who is asked to say which they plan to study.
     */
    public function readsFieldMentions(ApplicantProfile $profile): bool
    {
        try {
            $applicant = $this->applicant($profile);

            return $applicant['programmes']->isNotEmpty() || $applicant['mode'] === ApplicantProgrammes::INTENDED;
        } catch (QueryException) {
            return false;
        }
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

    /**
     * Whether the provider chose "All programmes at this level" on purpose. Then no programme or field rule
     * applies - not even one read out of the listing's own words, which a deliberate answer outranks.
     */
    public static function isOpenToAll(Opportunity $listing): bool
    {
        return $listing->open_to_all_programmes === true;
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

        if (self::isOpenToAll($listing)) {
            return [collect(), null];
        }

        // The words. Programmes named in them always apply; fields named in them apply here only for
        // an applicant who has chosen a programme - for anyone else the older field rule reads them.
        $rows = collect();
        $origin = null;
        $withFields = $this->readsFieldMentions($profile);

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

    /** @return array{mode: string, programmes: Collection<int, Programme>, legacy: Collection<int, Field>, institutions: array<int, int>, institutionNames: array<int, string>, levelKnown: bool} */
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

                // Where they study is the institution written on the profile; when the programme row does not carry it, it is read
                // from that, so it is only ever said once.
                if ($institutions === [] && ($written = \App\Models\Institution::matching($profile->institution_name)) !== null) {
                    $institutions = [(int) $written->id];
                    $names = [(int) $written->id => $written->name];
                }
            } elseif ($mode === ApplicantProgrammes::INTENDED) {
                $applied = $profile->relationLoaded('appliedInstitutions')
                    ? $profile->appliedInstitutions
                    : $profile->appliedInstitutions()->with('institution')->get();

                $institutions = $applied->pluck('institution_id')->map(fn ($i) => (int) $i)->all();
                $names = $applied->mapWithKeys(fn ($a) => [(int) $a->institution_id => $a->institution?->name ?? ''])->all();
            }
        }

        // Fields an enrolled student or graduate wants to study next (a Masters in Data Science after a BCom):
        // matched against a listing's fields as well as their own programme.
        $targets = collect();

        if ($profile->exists && $mode === ApplicantProgrammes::CURRENT) {
            $rows = $profile->relationLoaded('targetFields')
                ? $profile->targetFields
                : $profile->targetFields()->with('field.parent')->get();

            $targets = $rows->map(fn ($t) => $t->field)->filter()->values();
        }

        // An older free-text field of study, only until a programme is chosen.
        $legacy = collect();

        if ($programmes->isEmpty() && $mode === ApplicantProgrammes::CURRENT && filled($profile->field_of_study)) {
            $codes = LegacyFieldMap::codesFor($profile->field_of_study);
            $legacy = $codes === [] ? collect() : $this->mentions->fieldsByCode($codes);
        }

        return [
            'mode' => $mode, 'programmes' => $programmes, 'targets' => $targets, 'legacy' => $legacy, 'institutions' => $institutions, 'institutionNames' => $names,
            'levelKnown' => EducationLevel::canonical($profile->education_level) !== null,
        ];
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
        $targetMatch = false;

        if ($best === null) {
            foreach ($applicant['targets'] as $field) {
                $rank = $this->rankField($field, $fields);

                if ($rank !== null && ($best === null || $rank < $best)) {
                    $best = $rank;
                    $matched = $field->label();
                    $targetMatch = true;
                }
            }
        }

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
                $prefix . 'Programme: open to ' . $open . '; ' . match (true) {
                    $targetMatch => 'the field you want to study next, ',
                    $legacyMatch => 'your field, ',
                    default => 'your programme, ',
                } . $matched . ', fits.',
                required: $open,
                actual: $matched,
                fit: [RequirementOutcome::FIT_PROGRAMME, RequirementOutcome::FIT_NARROW_FIELD, RequirementOutcome::FIT_BROAD_FIELD][$best],
            );
        }

        $have = $applicant['programmes']->isNotEmpty() || $applicant['targets']->isNotEmpty() || $applicant['legacy']->isNotEmpty();
        $hasProgrammeRows = $programmeIds !== [];

        // Only fields to go on (an older field, or fields wanted next), and the listing names programmes:
        // a field cannot say which programme, so this asks for the programme rather than refusing.
        $onlyFields = $applicant['programmes']->isEmpty() && ($applicant['legacy']->isNotEmpty() || $applicant['targets']->isNotEmpty());

        if ($have && ! ($onlyFields && $hasProgrammeRows)) {
            $parts = [];

            if ($applicant['programmes']->isNotEmpty()) {
                $parts[] = ($applicant['programmes']->count() === 1 ? 'your programme is ' : 'your programmes are ') . $applicant['programmes']->pluck('name')->implode(', ');
            } elseif ($applicant['legacy']->isNotEmpty()) {
                $parts[] = 'your field is ' . $applicant['legacy']->map->label()->implode(', ');
            }

            if ($applicant['targets']->isNotEmpty()) {
                $parts[] = ($applicant['targets']->count() === 1 ? 'the field you want to study next is ' : 'the fields you want to study next are ')
                    . $applicant['targets']->map->label()->implode(', ');
            }

            $yours = implode(', and ', $parts);

            return RequirementOutcome::fail(
                RequirementOutcome::TYPE_PROGRAMME_SCOPE,
                $prefix . 'Programme: this scholarship is open to ' . $open . ', but ' . $yours . '.',
                required: $open,
                actual: $applicant['programmes']->pluck('name')->implode(', ') ?: null,
            );
        }

        if (! $applicant['levelKnown']) {
            return RequirementOutcome::needsInfo(
                RequirementOutcome::TYPE_PROGRAMME_SCOPE,
                $prefix . 'Programme: open to ' . $open . ' - add your education level to your profile so we can check it.',
                ScholarFitFieldNames::EDUCATION_LEVEL,
                required: $open,
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
            $prefix . 'Programme: open to ' . $open . ' - '
                . ($applicant['mode'] === ApplicantProgrammes::INTENDED ? 'add the programme you plan to study' : 'add your programme')
                . ' to your profile so we can check it.',
            ScholarFitFieldNames::PROGRAMME,
            required: $open,
        );
    }

    /**
     * A school-leaver whose chosen programmes are all at another level than the listing's.
     *
     * Their programmes say what they want to study next. An A-Level student who chose only diplomas has said
     * they are looking for a diploma, so a degree award is outside what they have chosen. That is a note and an
     * ordering, never a refusal: a preference is not an eligibility requirement, and they may well want the award
     * anyway. Nothing to say when they have chosen nothing yet (that is "needs information", elsewhere), or when
     * the listing is not for a level a school-leaver chooses programmes at.
     */
    private function intendedLevelOutcome(Opportunity $listing, array $applicant): ?RequirementOutcome
    {
        $level = EducationLevel::canonical($listing->education_level);

        if ($applicant['mode'] !== ApplicantProgrammes::INTENDED || $applicant['programmes']->isEmpty()
            || ! in_array($level, ApplicantProgrammes::LEAVER_LEVELS, true)) {
            return null;
        }

        if ($applicant['programmes']->contains(fn (Programme $p) => $p->education_level === $level)) {
            return null;
        }

        $levels = $applicant['programmes']->map(fn (Programme $p) => EducationLevel::label($p->education_level))->unique()->implode(' and ');

        return RequirementOutcome::note(
            RequirementOutcome::TYPE_PROGRAMME_SCOPE,
            false,
            'Outside your chosen programmes: this scholarship funds ' . EducationLevel::label($level) . ' study, but the programmes you chose are at '
                . $levels . ' level (' . $applicant['programmes']->pluck('name')->implode(', ') . '). This does not rule you out - '
                . 'your chosen programmes only change the order listings are shown in. Your choices have not been changed.',
            required: EducationLevel::label($level),
            actual: $applicant['programmes']->pluck('name')->implode(', '),
        );
    }

    /**
     * A school-leaver's programmes are preferences, so a listing open to other programmes is outside them rather than
     * closed to them: the refusal becomes a note. A student who is enrolled is judged on the programme they are on, as
     * before, and what the applicant has not said stays a question.
     */
    private function asPreference(RequirementOutcome $outcome, array $applicant): RequirementOutcome
    {
        if ($applicant['mode'] !== ApplicantProgrammes::INTENDED || $outcome->passed || $outcome->advisory || $outcome->needsInformation) {
            return $outcome;
        }

        return RequirementOutcome::note(
            RequirementOutcome::TYPE_PROGRAMME_SCOPE,
            false,
            'Outside your chosen programmes: ' . preg_replace('/^Programme: /', '', $outcome->message)
                . ' This does not rule you out - your chosen programmes only change the order listings are shown in, and your choices '
                . 'have not been changed. Check that you would study one of these before you apply.',
            required: $outcome->required,
            actual: $outcome->actual,
        );
    }

    /** The applicant with only their chosen programmes at the listing's level, for a school-leaver; anyone else as they are. */
    private function atListingLevel(Opportunity $listing, array $applicant): array
    {
        $level = EducationLevel::canonical($listing->education_level);

        if ($applicant['mode'] === ApplicantProgrammes::INTENDED && in_array($level, ApplicantProgrammes::LEAVER_LEVELS, true)) {
            $applicant['programmes'] = $applicant['programmes']->filter(fn (Programme $p) => $p->education_level === $level)->values();
        }

        return $applicant;
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

    /**
     * How closely a field sits to what the listing names: 1 = the same specific field, or a narrow field it is in; 2 = a
     * broad field it is in. A field that is only a broader heading than the one listed never matches (a programme filed under
     * "Business and administration" is not thereby "Finance"), and neighbouring fields never match each other.
     */
    private function rankField(Field $field, Collection $listed): ?int
    {
        $index = app(FieldIndex::class);
        $best = null;

        foreach ($listed as $wanted) {
            if (! $index->isWithin($field->id, $wanted->id)) {
                continue;
            }

            $rank = $index->levelOf($wanted->id) === Field::LEVEL_BROAD ? 2 : 1;

            if ($best === null || $rank < $best) {
                $best = $rank;
            }
        }

        return $best;
    }

    // ------------------------------------------------------------ institutions --

    /**
     * The institutions a home listing names that are not in the catalogue, as {name, country} rows. None for study abroad,
     * where the institutions are hosts the student goes to after the award and are shown on the listing instead.
     *
     * @return array<int, array{name: string, country?: ?string}>
     */
    private function unlisted(Opportunity $listing): array
    {
        if (ListingScopes::isAbroad($listing->country)) {
            return [];
        }

        return array_values(array_filter((array) ($listing->custom_institutions ?? []), 'is_array'));
    }

    /**
     * Where the award is to be studied, said to the applicant. A note: it states what the provider intends and leaves
     * the applicant to judge whether they would study there, and the provider to judge it at review (the note is kept in
     * the application's snapshot). It is never a pass, a failure or a question, because nothing on a profile can say
     * where someone WILL study - only where they are, or have applied, today.
     *
     * @param  Collection<int, OpportunityScope>  $where  the catalogue institutions
     * @param  array<int, array{name: string, country?: ?string}>  $unlisted  typed-in ones
     */
    private function destinationNote(Collection $where, array $unlisted): RequirementOutcome
    {
        $named = app(ListingScopes::class)->describeInstitutions($where, $unlisted) ?? '';

        return RequirementOutcome::note(
            RequirementOutcome::TYPE_INSTITUTION_SCOPE,
            true,
            'Where you would study: this scholarship is for study at ' . $named . '. You do not have to be there already - '
                . 'it is where you would study if you are awarded it.',
            required: $named,
        );
    }

    /** "Scholarship title states " when the rule came from the words rather than a choice. */
    private function prefix(?array $origin): string
    {
        return $origin === null ? '' : 'Scholarship ' . $origin['source'] . ' states: ';
    }
}
