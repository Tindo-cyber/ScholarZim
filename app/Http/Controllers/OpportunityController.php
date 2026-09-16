<?php

namespace App\Http\Controllers;

use App\Models\AcademicQualification;
use App\Models\AcademicSubject;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Services\ApplicationService;
use App\Services\OpportunityService;
use App\Services\SavedScholarshipService;
use App\Support\Academic\AcademicCatalogue;
use App\Support\FormOptions;
use App\Support\ZimbabweLocalities;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Validation\ValidationException;

class OpportunityController extends Controller
{
    public function __construct(
        private readonly OpportunityService $opportunityService,
        private readonly SavedScholarshipService $savedScholarshipService,
        private readonly ApplicationService $applicationService,
    ) {
    }

    /** Signed-in browse view; same data as the public list plus save state. */
    public function index(Request $request)
    {
        $filters = PublicController::filtersFrom($request);

        return view('opportunities.list', [
            'opportunities' => $this->opportunityService->search($filters),
            'providerNames' => $this->opportunityService->providerNames(),
            'targetFields' => $this->opportunityService->targetFields(),
            'filters' => $filters,
            'savedIds' => $this->savedScholarshipService->savedIds($request->user()),
            'appliedIds' => $this->applicationService->appliedIds($request->user()),
            'accepted' => $this->applicationService->acceptedByOpportunity($request->user()),
        ]);
    }

    public function create()
    {
        return view('opportunities.create', [
            'educationLevels' => FormOptions::targetEducationLevelGroups(),
            'minimumLevels' => FormOptions::educationLevelGroups(),
            'fields' => FormOptions::FIELDS_OF_STUDY,
            'settlementTypes' => \App\Services\ScholarFit\Taxonomy\SettlementType::ALL,
            'fundingTypes' => FormOptions::FUNDING_TYPES,
            'targetFieldSuggestions' => $this->opportunityService->targetFields(),
            'awardingBodySuggestions' => $this->opportunityService->providerNames(),
            'currencies' => FormOptions::CURRENCIES,
            'defaultCurrency' => FormOptions::DEFAULT_CURRENCY,
            'provinces' => FormOptions::ZIMBABWE_PROVINCES,
            'qualifications' => $qualifications = $this->qualificationCatalogue(),
            'qualificationCatalogue' => $this->qualificationCataloguePayload($qualifications),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'provider_display_name' => ['nullable', 'string', 'max:255'],
            'education_level' => ['nullable', Rule::in(\App\Support\EducationLevel::TARGET_LEVELS)],
            // The floor a specific listing actually requires, separate from
            // the level it targets - see EligibilityEvaluator::minimumLevel().
            // FORM_1 is deliberately not a valid value here: nothing "requires
            // at least Form 1", since Form 1 only ever appears as a target.
            'minimum_education_level' => ['nullable', Rule::in(\App\Support\EducationLevel::APPLICANT_LEVELS)],
            'target_field' => ['nullable', 'string', 'max:255'],
            'funding_type' => ['nullable', Rule::in(FormOptions::FUNDING_TYPES)],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'award_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'award_currency' => ['nullable', Rule::in(FormOptions::CURRENCIES)],
            'award_slots' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'is_renewable' => ['nullable', 'boolean'],
            'external_url' => ['nullable', 'url', 'max:500'],
            'min_academic_points' => ['nullable', 'integer', 'min:1', 'max:'.AcademicCatalogue::maxZimsecALevelPoints()],
            'max_age' => ['nullable', 'integer', 'min:10', 'max:99'],
            'required_province' => ['nullable', Rule::in(FormOptions::ZIMBABWE_PROVINCES)],
            // A specific place (e.g. "Gweru"), free text for the same reason
            // the applicant's own locality field is - no fixed list of every
            // Zimbabwean town would be worth maintaining.
            'target_locality' => ['nullable', 'string', 'max:100'],
            'target_settlement_type' => ['nullable', Rule::in(\App\Services\ScholarFit\Taxonomy\SettlementType::ALL)],
            'requires_results_certificate' => ['nullable', 'boolean'],
            'subject_requirements' => ['nullable', 'array'],
            'subject_requirements.*.qualification_id' => ['required', 'integer', 'exists:academic_qualifications,id'],
            'subject_requirements.*.subject_id' => ['required', 'integer', 'exists:academic_subjects,id'],
            'subject_requirements.*.minimum_grade' => ['nullable', 'string', 'max:20'],
        ]);

        $this->assertTargetLocalityMatchesProvince($data);

        try {
            $opportunity = $this->opportunityService->create($data, $request->user());
            $this->saveSubjectRequirements($opportunity, $data['subject_requirements'] ?? []);
        } catch (UnauthorizedException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('provider.dashboard')
            ->with('successMessage', 'Scholarship submitted for review. It goes live once an administrator approves it.');
    }

    public function edit(Request $request, int $id)
    {
        try {
            $opportunity = $this->opportunityService->findOwnedOrFail($id, $request->user());
        } catch (\RuntimeException $e) {
            abort(404);
        }

        if ($opportunity->isWithdrawn()) {
            return redirect()
                ->route('provider.dashboard')
                ->with('errorMessage', 'This scholarship has been withdrawn and can no longer be edited.');
        }

        return view('opportunities.edit', [
            'opportunity' => $opportunity,
            'educationLevels' => FormOptions::targetEducationLevelGroups(),
            'minimumLevels' => FormOptions::educationLevelGroups(),
            'fields' => FormOptions::FIELDS_OF_STUDY,
            'settlementTypes' => \App\Services\ScholarFit\Taxonomy\SettlementType::ALL,
            'fundingTypes' => FormOptions::FUNDING_TYPES,
            'targetFieldSuggestions' => $this->opportunityService->targetFields(),
            'awardingBodySuggestions' => $this->opportunityService->providerNames(),
            'currencies' => FormOptions::CURRENCIES,
            'defaultCurrency' => FormOptions::DEFAULT_CURRENCY,
            'provinces' => FormOptions::ZIMBABWE_PROVINCES,
            'qualifications' => $qualifications = $this->qualificationCatalogue(),
            'qualificationCatalogue' => $this->qualificationCataloguePayload($qualifications),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'provider_display_name' => ['nullable', 'string', 'max:255'],
            'education_level' => ['nullable', Rule::in(\App\Support\EducationLevel::TARGET_LEVELS)],
            // The floor a specific listing actually requires, separate from
            // the level it targets - see EligibilityEvaluator::minimumLevel().
            // FORM_1 is deliberately not a valid value here: nothing "requires
            // at least Form 1", since Form 1 only ever appears as a target.
            'minimum_education_level' => ['nullable', Rule::in(\App\Support\EducationLevel::APPLICANT_LEVELS)],
            'target_field' => ['nullable', 'string', 'max:255'],
            'funding_type' => ['nullable', Rule::in(FormOptions::FUNDING_TYPES)],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'award_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'award_currency' => ['nullable', Rule::in(FormOptions::CURRENCIES)],
            'award_slots' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'is_renewable' => ['nullable', 'boolean'],
            'external_url' => ['nullable', 'url', 'max:500'],
            'min_academic_points' => ['nullable', 'integer', 'min:1', 'max:'.AcademicCatalogue::maxZimsecALevelPoints()],
            'max_age' => ['nullable', 'integer', 'min:10', 'max:99'],
            'required_province' => ['nullable', Rule::in(FormOptions::ZIMBABWE_PROVINCES)],
            // A specific place (e.g. "Gweru"), free text for the same reason
            // the applicant's own locality field is - no fixed list of every
            // Zimbabwean town would be worth maintaining.
            'target_locality' => ['nullable', 'string', 'max:100'],
            'target_settlement_type' => ['nullable', Rule::in(\App\Services\ScholarFit\Taxonomy\SettlementType::ALL)],
            'requires_results_certificate' => ['nullable', 'boolean'],
            'subject_requirements' => ['nullable', 'array'],
            'subject_requirements.*.qualification_id' => ['required', 'integer', 'exists:academic_qualifications,id'],
            'subject_requirements.*.subject_id' => ['required', 'integer', 'exists:academic_subjects,id'],
            'subject_requirements.*.minimum_grade' => ['nullable', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->assertTargetLocalityMatchesProvince($data);

        try {
            $opportunity = $this->opportunityService->update($id, $data, $request->user(), $data['reason']);
            $this->saveSubjectRequirements($opportunity, $data['subject_requirements'] ?? []);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('provider.dashboard')
            ->with('successMessage', '"' . $opportunity->title . '" was updated and re-submitted for review.');
    }

    public function extendDeadline(Request $request, int $id)
    {
        $data = $request->validate([
            'deadline' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $opportunity = $this->opportunityService->extendDeadline($id, $request->user(), $data['deadline'], $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', 'Deadline for "' . $opportunity->title . '" extended to ' . $opportunity->deadline->format('d M Y') . '.');
    }

    public function destroy(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $opportunity = $this->opportunityService->delete($id, $request->user(), $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('provider.dashboard')
            ->with('successMessage', '"' . $opportunity->title . '" was withdrawn.');
    }

    /**
     * A listing cannot target a town that is not in the province it restricts
     * to. Gwanda is in Matabeleland South, so a Midlands-only award targeting
     * Gwanda would exclude everyone it meant to reach.
     *
     * Only towns ZimbabweLocalities recognises are checked; an unfamiliar name
     * is accepted, which is why the field is free text in the first place.
     *
     * @throws ValidationException
     */
    private function assertTargetLocalityMatchesProvince(array $data): void
    {
        $reason = ZimbabweLocalities::mismatchReason(
            $data['target_locality'] ?? null,
            $data['required_province'] ?? null
        );

        if ($reason !== null) {
            throw ValidationException::withMessages(['target_locality' => $reason]);
        }
    }

    /** The qualification catalogue as the subject picker renders it. */
    private function qualificationCatalogue()
    {
        return AcademicQualification::query()
            ->active()
            ->with('activeSubjects')
            ->orderBy('ordering')
            ->get();
    }

    /**
     * The same catalogue as the JSON payload the requirement rows are driven
     * from: which subjects each qualification offers, and which grades it
     * awards. Both come from the qualification rows, so the picker cannot
     * offer a grade saveSubjectRequirements() would then reject.
     *
     * @return array<int, array{subjects: array<int, array{id: int, name: string}>, grades: array<int, string>}>
     */
    private function qualificationCataloguePayload($qualifications): array
    {
        return $qualifications->mapWithKeys(fn (AcademicQualification $q) => [
            $q->id => [
                // A subject carries its own grades only where it is awarded on a
                // different scale from the rest of its qualification - the
                // Cambridge IGCSE 9-1 syllabuses. Null means the
                // qualification's own scale applies.
                'subjects' => $q->activeSubjects
                    ->map(fn (AcademicSubject $s) => [
                        'id' => $s->id,
                        'name' => $s->label(),
                        'grades' => $s->hasOwnScheme() ? $s->grades() : null,
                    ])
                    ->values()
                    ->all(),
                'grades' => $q->grades(),
            ],
        ])->all();
    }

    /**
     * Brings the listing's subject requirements into line with the submitted
     * set.
     *
     * Upserted on (opportunity_id, subject_id) - the key the table enforces -
     * so an edit updates the rows that are still there and removes only the
     * ones the provider actually deleted.
     *
     * The bug this replaces was in the form rather than here: existing rows
     * rendered their minimum grade as a readonly input with no `name`, so it
     * was never submitted, `minimum_grade` was nullable, and this method
     * deleted and reinserted the lot. Editing a listing's title therefore
     * reset every subject rule to "any grade". The form now posts the grade
     * as an editable named field, and this method no longer destroys a row it
     * is about to recreate.
     *
     * A grade the requirement's own qualification does not award is rejected:
     * a bar nobody can be measured against is not a rule.
     */
    private function saveSubjectRequirements(Opportunity $opportunity, array $requirements): void
    {
        $keptIds = [];

        foreach ($requirements as $index => $req) {
            $qualification = AcademicQualification::find($req['qualification_id'] ?? null);
            $subject = AcademicSubject::find($req['subject_id'] ?? null);

            if ($qualification === null || $subject === null
                || (int) $subject->qualification_id !== (int) $qualification->id) {
                throw ValidationException::withMessages([
                    "subject_requirements.$index.subject_id" => 'Choose a subject offered under the selected qualification.',
                ]);
            }

            $grade = null;

            if (filled($req['minimum_grade'] ?? null)) {
                // Checked against the subject's own scale where it has one. A
                // requirement of "6 or better" is meaningful on a Cambridge
                // IGCSE 9-1 syllabus and meaningless on an A*-G one, even
                // though both sit under Cambridge IGCSE.
                $grade = $subject->canonicalGrade($req['minimum_grade']);

                if ($grade === null) {
                    $awardedBy = $subject->hasOwnScheme() ? $subject->name : $qualification->name;

                    throw ValidationException::withMessages([
                        "subject_requirements.$index.minimum_grade" => 'Choose a grade that '.$awardedBy
                            .' awards ('.implode(', ', $subject->grades()).').',
                    ]);
                }
            }

            $requirement = OpportunitySubjectRequirement::updateOrCreate(
                [
                    'opportunity_id' => $opportunity->opportunity_id,
                    'subject_id' => $subject->id,
                ],
                [
                    'qualification_id' => $qualification->id,
                    'minimum_grade' => $grade,
                ]
            );

            $keptIds[] = $requirement->id;
        }

        $opportunity->subjectRequirements()->whereNotIn('id', $keptIds)->delete();
    }
}
