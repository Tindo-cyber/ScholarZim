<?php

namespace App\Services;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\Institution;
use App\Services\Catalogue\ApplicantProgrammes;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\DocumentChecklist;
use App\Support\EducationLevel;
use App\Support\FormOptions;
use App\Support\Gender;
use App\Services\ScholarFit\Taxonomy\SettlementType;

/**
 * Where an applicant stands against ONE scholarship: what ScholarZim already has, and only what it still needs.
 *
 * The apply page reads this to decide between "add what is missing", "you are not eligible" and "write your statement
 * and submit". It reuses what already decides those things instead of deciding again:
 *
 *   - ApplicantProfile::missingFields() and missingGuardianDetails(): the profile gate ApplicationService::submit()
 *     enforces;
 *   - the EligibilityResult's pending information: what ScholarFit could not check for want of an answer;
 *   - ApplicantProfile::documentChecklist($opportunity): the required documents for this applicant and this award.
 *
 * What is missing is split by how it can be supplied. A simple detail (a province, a date of birth, a guardian's name)
 * is asked for in place and saved to the profile. A document is uploaded in place and saved to the profile. Something
 * only the profile can hold (structured results, the education level, a programme) is a link to that section, so a
 * second, competing copy of it is never created. Nothing is ever asked for twice, and nothing already on file is.
 *
 * It only describes. ApplicationService::submit() makes the same checks again and is the one that refuses.
 */
final class ApplicationReadiness
{
    /**
     * Checklist label or ScholarFit field name => the profile field it is. null means "the profile page holds it".
     * Documents are not here: they come from the document checklist.
     */
    private const FIELD_FOR = [
        'institution' => 'institution_name',
        'field of study' => 'field_of_study',
        'province' => 'province',
        'locality' => 'locality',
        'settlement type' => 'settlement_type',
        'date of birth' => 'date_of_birth',
        'gender' => 'gender',
        'short biography' => 'biography',
    ];

    /** Only the profile page can hold these; where, and the plain name to show. */
    private const PROFILE_ONLY = [
        'education level' => ['Education level', 'education_level'],
        'academic results' => ['Academic results', 'academic-results'],
        'programme' => ['Programme', 'programme-card'],
    ];

    /** @var array<string, string> the labels the profile checklist uses, lower-cased => the same names as above */
    private const CHECKLIST_ALIASES = [
        'institution' => 'institution',
        'field of study' => 'field of study',
        'province' => 'province',
        'date of birth' => 'date of birth',
        'gender' => 'gender',
        'short biography' => 'short biography',
        'education level' => 'education level',
        'academic results' => 'academic results',
    ];

    /**
     * @param  array<int, string>  $guardianMissing  what a minor still has to give, in words
     * @param  array<string, array<string, mixed>>  $details  profile field => how to ask for it (see inputFor())
     * @param  array<int, array{label: string, anchor: string}>  $profileOnly  things only the profile page can hold
     * @param  bool  $asksInstitutions  a school-leaver is asked where they have applied, because this scholarship is
     *                                  limited to named institutions and they have none on file to check it against
     * @param  bool  $correctsInstitutions  they have a list on file, but it does not include an institution this
     *                                      scholarship is limited to: it is shown for them to correct
     */
    private function __construct(
        public readonly ApplicantProfile $profile,
        public readonly Opportunity $opportunity,
        public readonly ?EligibilityResult $fit,
        public readonly array $guardianMissing,
        public readonly array $details,
        public readonly array $profileOnly,
        public readonly DocumentChecklist $documents,
        public readonly bool $asksInstitutions = false,
        public readonly bool $correctsInstitutions = false,
    ) {
    }

    public static function for(ApplicantProfile $profile, Opportunity $opportunity, ?EligibilityResult $fit): self
    {
        $details = [];
        $profileOnly = [];
        $asksInstitutions = false;
        $correctsInstitutions = false;

        $note = static function (string $name) use (&$details, &$profileOnly, $profile): void {
            $name = strtolower($name);

            if (isset(self::PROFILE_ONLY[$name])) {
                [$label, $anchor] = self::PROFILE_ONLY[$name];
                $profileOnly[$anchor] = ['label' => $label, 'anchor' => $anchor];

                return;
            }

            $field = self::FIELD_FOR[$name] ?? null;

            if ($field !== null && blank($profile->{$field})) {
                $details[$field] ??= self::inputFor($field, $profile);
            }
        };

        // What the profile gate would refuse for, then what ScholarFit could not check. Documents are the checklist's.
        foreach ($profile->missingFields() as $label) {
            $name = self::CHECKLIST_ALIASES[strtolower($label)] ?? null;

            if ($name !== null) {
                $note($name);
            }
        }

        foreach ($fit?->pendingInformation() ?? [] as $outcome) {
            // A listing whose wording could not be read is not something the profile can answer.
            if ($outcome->review) {
                continue;
            }

            // A scholarship limited to named institutions. A school-leaver has no current institution to check, so where
            // they have applied or hold an offer is asked for here, on the application that needs it; a student who is
            // already studying sets where with their programme, which only the profile page holds.
            if ($outcome->type === RequirementOutcome::TYPE_INSTITUTION_SCOPE && strtolower((string) $outcome->missing) === 'institution') {
                if (ApplicantProgrammes::modeFor($profile) === ApplicantProgrammes::INTENDED) {
                    $asksInstitutions = true;
                } else {
                    $profileOnly['programme-card'] = ['label' => 'Where you study', 'anchor' => 'programme-card'];
                }

                continue;
            }

            $note((string) $outcome->missing);
        }

        // A list that is on file but does not fit this scholarship is not a dead end: where a school-leaver has applied
        // can change, and it is only ever kept up here, so it is shown for them to correct.
        if (ApplicantProgrammes::modeFor($profile) === ApplicantProgrammes::INTENDED) {
            foreach ($fit?->failedRequirements() ?? [] as $outcome) {
                if ($outcome->type === RequirementOutcome::TYPE_INSTITUTION_SCOPE) {
                    $correctsInstitutions = true;
                }
            }
        }

        // Keep the profile form's own order, so the questions read in the order the profile does.
        $details = array_replace(array_intersect_key(array_flip(self::order()), $details), $details);

        return new self(
            $profile,
            $opportunity,
            $fit,
            $profile->missingGuardianDetails(),
            $details,
            array_values($profileOnly),
            $profile->documentChecklist($opportunity),
            $asksInstitutions,
            $correctsInstitutions,
        );
    }

    /** @return array<int, string> */
    private static function order(): array
    {
        return ['gender', 'date_of_birth', 'institution_name', 'field_of_study', 'province', 'locality', 'settlement_type', 'biography'];
    }

    /**
     * How one profile field is asked for in place.
     *
     * @return array{field: string, label: string, kind: string, options: array<string, string>, hint: ?string}
     */
    private static function inputFor(string $field, ApplicantProfile $profile): array
    {
        return match ($field) {
            'gender' => ['field' => $field, 'label' => 'Gender', 'kind' => 'select', 'options' => collect(Gender::ALL)->mapWithKeys(fn ($g) => [$g => Gender::label($g)])->all(), 'hint' => null],
            'date_of_birth' => ['field' => $field, 'label' => 'Date of birth', 'kind' => 'date', 'options' => [], 'hint' => 'Used to check age limits.'],
            'institution_name' => ['field' => $field, 'label' => 'Institution / school', 'kind' => 'text', 'options' => [], 'hint' => null],
            'field_of_study' => ['field' => $field, 'label' => 'Field of study', 'kind' => 'text', 'options' => [], 'hint' => 'For example Information Systems or Nursing.'],
            'province' => ['field' => $field, 'label' => 'Province', 'kind' => 'select', 'options' => array_combine(FormOptions::ZIMBABWE_PROVINCES, FormOptions::ZIMBABWE_PROVINCES), 'hint' => null],
            'locality' => ['field' => $field, 'label' => 'Town / city', 'kind' => 'text', 'options' => [], 'hint' => null],
            'settlement_type' => ['field' => $field, 'label' => 'Home area', 'kind' => 'select', 'options' => collect(SettlementType::ALL)->mapWithKeys(fn ($t) => [$t => SettlementType::label($t)])->all(), 'hint' => null],
            'biography' => ['field' => $field, 'label' => 'Short biography', 'kind' => 'textarea', 'options' => [], 'hint' => 'A few sentences about you.'],
        };
    }

    // ---------------------------------------------------------------- the questions --

    public function isIneligible(): bool
    {
        return $this->fit?->isIneligible() === true;
    }

    /** What a minor still owes: a guardian's name, phone number, relationship and the confirming tick. */
    public function needsGuardian(): bool
    {
        return $this->guardianMissing !== [];
    }

    /** Details to ask for in place, plus the guardian's if a minor has not given them. @return array<int, string> the profile fields */
    public function inlineFields(): array
    {
        $fields = array_keys($this->details);

        if ($this->needsGuardian()) {
            $fields = array_merge($fields, $this->guardianFields());
        }

        return $fields;
    }

    /** @return array<int, string> */
    public function guardianFields(): array
    {
        return array_values(array_filter(
            ['guardian_name', 'guardian_phone', 'guardian_relationship', 'guardian_confirmed'],
            fn (string $field) => match ($field) {
                'guardian_confirmed' => $this->profile->guardian_confirmed_at === null,
                default => blank($this->profile->{$field}),
            }
        ));
    }

    /** Whether anything about the applicant's own details stands between them and the statement. */
    public function needsDetails(): bool
    {
        return $this->needsGuardian() || $this->details !== [] || $this->profileOnly !== [] || $this->asksInstitutions;
    }

    /** Whether the school-leaver is asked where they have applied (up to three institutions). */
    public function asksAppliedInstitutions(): bool
    {
        return $this->asksInstitutions;
    }

    /** Whether a list that is on file, and does not fit this scholarship, is offered for correction. */
    public function correctsAppliedInstitutions(): bool
    {
        return $this->correctsInstitutions;
    }

    /** Whether the institutions control is drawn at all: asked for, or shown to be corrected. */
    public function offersInstitutions(): bool
    {
        return $this->asksInstitutions || $this->correctsInstitutions;
    }

    /** @return array<int, int> what is already saved, so changing it starts from the truth */
    public function savedInstitutionIds(): array
    {
        return $this->profile->appliedInstitutions()->pluck('institution_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The institutions to choose from, grouped as x-limited-choice draws them.
     *
     * @param  array<int, int>  $selected
     * @return array<int, array{label: string, options: array<int, array<string, mixed>>}>
     */
    public function institutionGroups(array $selected): array
    {
        return Institution::active()->orderBy('type')->orderBy('name')->get()->groupBy('type')
            ->map(fn ($institutions, $type) => [
                'label' => Institution::TYPE_LABELS[$type] ?? $type,
                'options' => $institutions->map(fn ($institution) => [
                    'id' => $institution->id,
                    'label' => $institution->name,
                    'search' => strtolower($institution->name . ' ' . $institution->code),
                    'selected' => in_array($institution->id, $selected, true),
                ])->all(),
            ])->values()->all();
    }

    /**
     * Required documents that are missing - but only worth asking for while the application can still go ahead;
     * nobody is asked to upload a certificate for a scholarship they cannot be considered for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function missingDocuments(): array
    {
        return $this->isIneligible() ? [] : $this->documents->missing();
    }

    /** @return array<int, string> */
    public function missingDocumentTypes(): array
    {
        return array_column($this->missingDocuments(), 'type');
    }

    /** True when the applicant can be asked for something in place (as opposed to being sent to their profile). */
    public function hasInlineQuestions(): bool
    {
        return $this->inlineFields() !== [] || $this->offersInstitutions() || $this->missingDocuments() !== [];
    }

    /** True while the statement and Submit must wait: something is missing, or the applicant cannot be considered. */
    public function blocks(): bool
    {
        return $this->needsDetails() || $this->isIneligible() || $this->missingDocuments() !== [] || $this->fit?->needsProfileInformation() === true;
    }

    /** Where the profile page holds something, for a link back to this application afterwards. */
    public function profileLink(string $anchor): string
    {
        return route('applicant.profile', ['apply' => $this->opportunity->opportunity_id]) . '#' . $anchor;
    }

    /** The education level in words, for the summary of what is already known. */
    public function levelLabel(): string
    {
        return EducationLevel::label($this->profile->education_level);
    }
}
