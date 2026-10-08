<?php

namespace App\Http\Requests;

use App\Models\AcademicQualification;
use App\Services\ScholarFit\Taxonomy\SettlementType;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\FormOptions;
use App\Support\OpportunityFormMessages;
use App\Support\OpportunityLevelRules;
use App\Support\ZimbabweLocalities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * A provider's listing form, validated.
 *
 * The rules used to be written out twice, once in OpportunityController::store()
 * and once in update(), and had already drifted: a rule changed in one place had
 * to be remembered in the other. UpdateOpportunityRequest extends this class and
 * adds only what an edit needs, so the two forms cannot disagree about what a
 * valid listing is.
 *
 * Field rules say whether each value is well formed on its own. The checks in
 * after() say whether the values make sense together - a minimum level above
 * the target, A-Level points on a Form 1 award - which per-field rules cannot
 * express. Those are in OpportunityLevelRules so the reasoning is stated once.
 *
 * Authorisation is the route's concern (provider role, active account) and the
 * service's (ownership of the listing being edited); this request only validates.
 */
class StoreOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Number the submitted subject rows 0..n-1, in the order they were sent.
     *
     * A provider who removes a row in the browser leaves a gap in the keys it
     * posts (0, 2, 3). Validation messages say "Row N" from the key, so with the
     * gap they would name a row that is not the one on screen, and the form
     * re-rendered after a failed save would carry the gap forward. Renumbering
     * first makes the key, the message and the row the provider sees all agree.
     */
    protected function prepareForValidation(): void
    {
        $rows = $this->input('subject_requirements');

        if (is_array($rows)) {
            $this->merge(['subject_requirements' => array_values($rows)]);
        }

        // The form's script adds this marker once it has started hiding fields the
        // chosen level does not use. With it, a value left in such a field is stale
        // and is dropped; without it (JavaScript off, every field visible) a value
        // is something the provider typed and is judged by the ordinary rules.
        $level = $this->input('education_level');

        if ($this->boolean('level_driven') && filled($level) && in_array($level, EducationLevel::TARGET_LEVELS, true)) {
            $this->merge(OpportunityLevelRules::clearInapplicable($this->all(), $level));
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'provider_display_name' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', Rule::in(FormOptions::COUNTRIES)],
            'education_level' => ['nullable', Rule::in(EducationLevel::TARGET_LEVELS)],
            // The floor a specific listing actually requires, separate from
            // the level it targets - see EligibilityEvaluator::minimumLevel().
            // FORM_1 is deliberately not a valid value here: nothing "requires
            // at least Form 1", since Form 1 only ever appears as a target.
            'minimum_education_level' => ['nullable', Rule::in(EducationLevel::APPLICANT_LEVELS)],
            'target_field' => ['nullable', 'string', 'max:255'],
            'funding_type' => ['nullable', Rule::in(FormOptions::FUNDING_TYPES)],
            'deadline' => $this->deadlineRules(),
            'award_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'award_currency' => ['nullable', Rule::in(FormOptions::CURRENCIES)],
            'award_slots' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'is_renewable' => ['nullable', 'boolean'],
            'external_url' => ['nullable', 'url', 'max:500'],
            'min_academic_points' => ['nullable', 'integer', 'min:1', 'max:' . AcademicCatalogue::maxZimsecALevelPoints()],
            'max_age' => ['nullable', 'integer', 'min:10', 'max:99'],
            'required_province' => ['nullable', Rule::in(FormOptions::ZIMBABWE_PROVINCES)],
            // A specific place (e.g. "Gweru"), free text for the same reason
            // the applicant's own locality field is - no fixed list of every
            // Zimbabwean town would be worth maintaining.
            'target_locality' => ['nullable', 'string', 'max:100'],
            'target_settlement_type' => ['nullable', Rule::in(SettlementType::ALL)],
            'requires_results_certificate' => ['nullable', 'boolean'],
            'subject_requirements' => ['nullable', 'array'],
            'subject_requirements.*.qualification_id' => ['required', 'integer', 'exists:academic_qualifications,id'],
            'subject_requirements.*.subject_id' => ['required', 'integer', 'exists:academic_subjects,id'],
            'subject_requirements.*.minimum_grade' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** @return array<int, mixed> */
    protected function deadlineRules(): array
    {
        return ['nullable', 'date', 'after_or_equal:today'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return OpportunityFormMessages::subjectRequirements();
    }

    /**
     * Cross-field checks. Each runs only when the fields it reads passed their
     * own rules, so a provider is told about the malformed value first and not
     * also about a conflict it takes part in.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->checkLevelCombinations($validator);
                $this->checkLocality($validator);
                $this->checkSubjectRows($validator);
            },
        ];
    }

    private function checkLevelCombinations(Validator $validator): void
    {
        $errors = $validator->errors();
        $target = $this->targetLevel($errors);

        $minimum = $errors->has('minimum_education_level') ? null : $this->input('minimum_education_level');

        if ($problem = OpportunityLevelRules::minimumLevelProblem($minimum, $target)) {
            $errors->add('minimum_education_level', $problem);
        }

        $maxAge = $errors->has('max_age') || ! filled($this->input('max_age')) ? null : (int) $this->input('max_age');

        if ($problem = OpportunityLevelRules::maxAgeProblem($maxAge, $target)) {
            $errors->add('max_age', $problem);
        }

        $points = $errors->has('min_academic_points') || ! filled($this->input('min_academic_points'))
            ? null
            : (int) $this->input('min_academic_points');

        if ($problem = OpportunityLevelRules::pointsProblem($points, $target)) {
            $errors->add('min_academic_points', $problem);
        }

        if ($problem = OpportunityLevelRules::resultsCertificateProblem($this->boolean('requires_results_certificate'), $target)) {
            $errors->add('requires_results_certificate', $problem);
        }
    }

    /**
     * A listing cannot target a town that is not in the province it restricts
     * to. Gwanda is in Matabeleland South, so a Midlands-only award targeting
     * Gwanda would exclude everyone it meant to reach.
     *
     * Only towns ZimbabweLocalities recognises are checked; an unfamiliar name
     * is accepted, which is why the field is free text in the first place.
     */
    private function checkLocality(Validator $validator): void
    {
        $errors = $validator->errors();

        if ($errors->has('target_locality') || $errors->has('required_province')) {
            return;
        }

        $reason = ZimbabweLocalities::mismatchReason(
            $this->input('target_locality'),
            $this->input('required_province')
        );

        if ($reason !== null) {
            $errors->add('target_locality', $reason);
        }
    }

    /**
     * Each row's qualification must fit the target level, and a subject may be
     * required once. A repeated subject used to be merged silently by
     * updateOrCreate, so the second row's grade quietly replaced the first's and
     * the provider never learned one of their rows had been discarded.
     */
    private function checkSubjectRows(Validator $validator): void
    {
        $errors = $validator->errors();
        $rows = $this->input('subject_requirements');

        if (! is_array($rows)) {
            return;
        }

        $target = $this->targetLevel($errors);
        $qualifications = AcademicQualification::whereIn(
            'id',
            collect($rows)->pluck('qualification_id')->filter(fn ($id) => is_numeric($id))->all()
        )->get()->keyBy('id');

        $firstRowFor = [];

        foreach (array_values($rows) as $index => $row) {
            $position = $index + 1;
            $qualificationId = $row['qualification_id'] ?? null;
            $subjectId = $row['subject_id'] ?? null;

            $qualification = is_numeric($qualificationId) ? $qualifications->get((int) $qualificationId) : null;

            if ($qualification && ! $errors->has("subject_requirements.$index.qualification_id")
                && ($problem = OpportunityLevelRules::qualificationProblem($qualification, $target))) {
                $errors->add("subject_requirements.$index.qualification_id", "Row $position: $problem");
            }

            if (! is_numeric($subjectId) || $errors->has("subject_requirements.$index.subject_id")) {
                continue;
            }

            $subjectId = (int) $subjectId;

            if (isset($firstRowFor[$subjectId])) {
                $errors->add(
                    "subject_requirements.$index.subject_id",
                    "Row $position: this subject is already required in row {$firstRowFor[$subjectId]}. Remove one of the two."
                );

                continue;
            }

            $firstRowFor[$subjectId] = $position;
        }
    }

    /** The target level as submitted, or null when it is blank or failed its own rule. */
    private function targetLevel($errors): ?string
    {
        return $errors->has('education_level') ? null : (filled($this->input('education_level')) ? $this->input('education_level') : null);
    }
}
