<?php

namespace App\Http\Requests;

use App\Models\AcademicQualification;
use App\Services\Catalogue\ListingScopes;
use App\Services\ScholarFit\Taxonomy\SettlementType;
use App\Support\Academic\AcademicCatalogue;
use App\Support\Deadlines;
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

        // Form 1, O-Level and A-Level scholarships are for study in Zimbabwe. A country left out is Zimbabwe. The form's script
        // fixes the field to Zimbabwe and marks itself with level_driven, so a different country still in the request is a
        // value left over from an earlier level and is replaced; without the marker (no script, or a hand-made request) a
        // foreign country is a contradiction and is refused in checkCountryForLevel() rather than quietly changed.
        if (EducationLevel::requiresZimbabwe($level)) {
            $sent = $this->input('country');
            $country = is_string($sent) ? trim($sent) : ($sent === null ? '' : null);

            // A non-text country is left for its own rule to refuse.
            if ($country !== null && ($country === '' || ($this->boolean('level_driven') && strcasecmp($country, FormOptions::DEFAULT_COUNTRY) !== 0))) {
                $this->merge(['country' => FormOptions::DEFAULT_COUNTRY]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['bail', 'required', 'string', 'max:255'],
            'description' => ['bail', 'required', 'string', 'max:10000'],
            'provider_display_name' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', Rule::in(FormOptions::countries())],
            'education_level' => ['nullable', Rule::in(EducationLevel::TARGET_LEVELS)],
            // The floor a specific listing actually requires, separate from
            // the level it targets - see EligibilityEvaluator::minimumLevel().
            // FORM_1 is deliberately not a valid value here: nothing "requires
            // at least Form 1", since Form 1 only ever appears as a target.
            'minimum_education_level' => ['nullable', Rule::in(EducationLevel::APPLICANT_LEVELS)],
            'funding_type' => ['nullable', Rule::in(FormOptions::FUNDING_TYPES)],
            'deadline' => $this->deadlineRules(),
            // Digits with at most two decimals, written plainly: no exponent (1e3), sign (+5), bare dot (.5 or 5.) or three decimals.
            'award_amount' => ['bail', 'nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'award_currency' => ['nullable', Rule::in(FormOptions::CURRENCIES)],
            // Required: a scholarship says how many students it will fund. A draft may omit it (ListingDraftService), but nothing
            // leaves draft - published, or sent for review - without it, because every such path validates here.
            'award_slots' => ['bail', 'required', 'regex:/^\d+$/', 'integer', 'min:1', 'max:5000'],
            'is_renewable' => ['nullable', 'boolean'],
            'external_url' => ['bail', 'nullable', 'string', 'url:http,https', 'max:500'],
            'min_academic_points' => ['bail', 'nullable', 'regex:/^\d+$/', 'integer', 'min:1', 'max:' . AcademicCatalogue::maxZimsecALevelPoints()],
            'max_age' => ['bail', 'nullable', 'regex:/^\d+$/', 'integer', 'min:' . EducationLevel::MINIMUM_APPLICANT_AGE, 'max:99'],
            'required_province' => ['nullable', Rule::in(FormOptions::ZIMBABWE_PROVINCES)],
            // A specific place (e.g. "Gweru"), free text for the same reason
            // the applicant's own locality field is - no fixed list of every
            // Zimbabwean town would be worth maintaining.
            'target_locality' => ['nullable', 'string', 'max:100'],
            'target_settlement_type' => ['nullable', Rule::in(SettlementType::ALL)],
            'requires_results_certificate' => ['nullable', 'boolean'],
            'subject_requirements' => ['nullable', 'array'],
            'subject_requirements.*.qualification_id' => ['required', 'integer', 'exists:academic_qualifications,id'],
            'subject_requirements.*.subject_id' => ['bail', 'required', 'integer', 'exists:academic_subjects,id'],
            'subject_requirements.*.minimum_grade' => ['nullable', 'string', 'max:20'],
            // What the listing is open to. Checked as a whole in checkScope().
            'scope_programmes' => ['nullable', 'array'],
            'scope_fields' => ['nullable', 'array'],
            'scope_institutions' => ['nullable', 'array'],
            // Institutions the catalogue does not list; each row is judged in ListingScopes::problems().
            'custom_institutions' => ['nullable', 'array', 'max:' . (ListingScopes::MAX_CUSTOM_INSTITUTIONS * 2)],
            'custom_institutions.*' => ['nullable', 'array'],
            'custom_institutions.*.name' => ['nullable', 'string', 'max:300'],
            'custom_institutions.*.country' => ['nullable', 'string', 'max:100'],
            'programme_suggestion' => ['nullable', 'string', 'max:200'],
            'programme_suggestion_field' => ['nullable', 'integer', 'exists:fields,id'],
            // The answer to "who is this open to?" - required at Certificate level and above, checked in checkScope().
            'open_to' => ['nullable', Rule::in(ListingScopes::OPEN_ANSWERS)],
            'institutions_open_to' => ['nullable', Rule::in([ListingScopes::INSTITUTIONS_CHOSEN, ListingScopes::INSTITUTIONS_ANY])],
            'accepts_o_level' => ['nullable', 'boolean'],
            'host_institutions' => ['nullable', 'string', 'max:500'],
            'other_conditions' => ['nullable', 'string', 'max:3000'],
        ];
    }

    /** @return array<int, mixed> */
    protected function deadlineRules(): array
    {
        // Strictly after today in Harare, not "not before today": a listing created for today would close as it opens.
        return ['bail', 'nullable', 'string', 'date_format:Y-m-d', 'after:' . Deadlines::today()->toDateString()];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return OpportunityFormMessages::subjectRequirements() + [
            'award_slots.required' => 'Enter the number of awards - how many students this scholarship will fund.',
            'award_slots.regex' => 'Enter the number of awards as a whole number, for example 5.',
            'award_slots.integer' => 'Enter the number of awards as a whole number, for example 5.',
            'award_slots.min' => 'There must be at least 1 award.',
            'award_slots.max' => 'The number of awards cannot be more than 5000.',
            'deadline.after' => 'The deadline must be after today. Choose tomorrow or later.',
            'deadline.date_format' => 'Enter the deadline as a date.',
            'max_age.min' => 'The maximum age must be at least ' . EducationLevel::MINIMUM_APPLICANT_AGE
                . ' because ScholarZim applicants must be at least ' . EducationLevel::MINIMUM_APPLICANT_AGE . ' years old.',
            'max_age.max' => 'The maximum age cannot be more than 99.',
            'max_age.regex' => 'Enter the maximum age as a whole number, for example 25.',
            'max_age.integer' => 'Enter the maximum age as a whole number, for example 25.',
        ];
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
                $this->checkProviderLevel($validator);
                $this->checkCountryForLevel($validator);
                $this->checkLevelCombinations($validator);
                $this->checkLocality($validator);
                $this->checkSubjectRows($validator);
                $this->checkScope($validator);
            },
        ];
    }

    /**
     * An organisation registered outside Zimbabwe funds Certificate, Diploma, Degree and above - not school levels, and
     * not "any level", which would include them. The form offers only those levels; this is the server saying the same.
     */
    private function checkProviderLevel(Validator $validator): void
    {
        if (! EducationLevel::isLimitedProvider($this->user()) || $validator->errors()->has('education_level')) {
            return;
        }

        $level = EducationLevel::canonical($this->input('education_level'));

        if ($level === null || ! in_array($level, EducationLevel::ABROAD_PROVIDER_LEVELS, true)) {
            $validator->errors()->add('education_level', 'An organisation registered outside Zimbabwe can fund Certificate, Diploma, Degree and postgraduate awards. Choose one of those levels.');
        }
    }

    /** Form 1, O-Level and A-Level are for study in Zimbabwe: any other destination, "any country" and "online" included, is refused. */
    private function checkCountryForLevel(Validator $validator): void
    {
        if (! EducationLevel::requiresZimbabwe($this->input('education_level')) || $validator->errors()->has('country')) {
            return;
        }

        $country = $this->input('country');

        if (! is_string($country) || strcasecmp(trim($country), FormOptions::DEFAULT_COUNTRY) !== 0) {
            $validator->errors()->add('country', EducationLevel::label(EducationLevel::canonical($this->input('education_level')))
                . ' scholarships are for study in Zimbabwe, so the country of study must be Zimbabwe. '
                . 'To fund study abroad, choose Certificate level or above.');
        }
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
    /** Programmes, fields and institutions: real, at the listing's level, and only where a level uses them. */
    private function checkScope(Validator $validator): void
    {
        $errors = $validator->errors();

        foreach (['scope_programmes', 'scope_fields', 'scope_institutions', 'custom_institutions', 'programme_suggestion', 'programme_suggestion_field'] as $key) {
            if ($errors->has($key)) {
                return;
            }
        }

        foreach (app(ListingScopes::class)->problems($this->all(), $this->targetLevel($errors), $this->user(), $this->keptFieldIds()) as $key => $message) {
            $errors->add($key, $message);
        }

        if ($errors->has('education_level') || $errors->has('country')) {
            return;
        }

        foreach (app(ListingScopes::class)->decisionProblems($this->all(), $this->targetLevel($errors), $this->input('country')) as $key => $message) {
            if (! $errors->has($key)) {
                $errors->add($key, $message);
            }
        }
    }

    /** The fields an edited listing already has, which stay valid even if the catalogue has since retired them. */
    private function keptFieldIds(): array
    {
        $id = $this->route('id');

        return $id === null ? [] : \App\Models\OpportunityScope::where('opportunity_id', $id)->whereNotNull('field_id')->pluck('field_id')->map(fn ($i) => (int) $i)->all();
    }

    private function targetLevel($errors): ?string
    {
        return $errors->has('education_level') ? null : (filled($this->input('education_level')) ? $this->input('education_level') : null);
    }
}
