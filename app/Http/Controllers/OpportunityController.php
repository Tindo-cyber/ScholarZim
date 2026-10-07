<?php

namespace App\Http\Controllers;

use App\Models\AcademicQualification;
use App\Http\Requests\StoreOpportunityRequest;
use App\Http\Requests\UpdateOpportunityRequest;
use App\Models\AcademicSubject;
use App\Models\Opportunity;
use App\Support\EditImpact;
use App\Services\ApplicationService;
use App\Services\OpportunityService;
use App\Services\SavedScholarshipService;
use App\Support\Academic\AcademicCatalogue;
use App\Support\FormOptions;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;

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
            'countries' => FormOptions::COUNTRIES,
            'targetFieldSuggestions' => $this->opportunityService->targetFields(),
            'currencies' => FormOptions::CURRENCIES,
            'defaultCurrency' => FormOptions::DEFAULT_CURRENCY,
            'provinces' => FormOptions::ZIMBABWE_PROVINCES,
            'qualifications' => $qualifications = $this->qualificationCatalogue(),
            'qualificationCatalogue' => $this->qualificationCataloguePayload($qualifications),
        ]);
    }

    public function store(StoreOpportunityRequest $request)
    {
        $this->authorize('create', Opportunity::class);

        try {
            $this->opportunityService->create($request->validated(), $request->user());
        } catch (UnauthorizedException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('provider.dashboard')
            ->with('successMessage', 'Scholarship submitted for review. It goes live once an administrator approves it.');
    }

    public function edit(Request $request, int $id)
    {
        $opportunity = $this->ownedListing($request, $id, 'update') ?? abort(404);

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
            'countries' => FormOptions::COUNTRIES,
            'targetFieldSuggestions' => $this->opportunityService->targetFields(),
            'currencies' => FormOptions::CURRENCIES,
            'defaultCurrency' => FormOptions::DEFAULT_CURRENCY,
            'provinces' => FormOptions::ZIMBABWE_PROVINCES,
            'qualifications' => $qualifications = $this->qualificationCatalogue(),
            'qualificationCatalogue' => $this->qualificationCataloguePayload($qualifications),
        ]);
    }

    public function update(UpdateOpportunityRequest $request, int $id)
    {
        $this->ownedListing($request, $id, 'update');
        $data = $request->validated();

        try {
            $opportunity = $this->opportunityService->update($id, $data, $request->user(), $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('provider.dashboard')
            ->with('successMessage', '"' . $opportunity->title . '" was updated and re-submitted for review.');
    }

    /**
     * What saving the form as it stands would do to this listing's place in
     * review - the answer behind the notice on the edit page. Writes nothing.
     *
     * The input is deliberately not validated: this describes a form still being
     * filled in, and an incomplete one is the normal case. The server's rule for
     * "is this a material change" is what runs, whatever the values; a value too
     * malformed for it simply gets no answer and the page keeps what it shows.
     */
    public function editImpact(Request $request, int $id)
    {
        $listing = $this->ownedListing($request, $id, 'update') ?? abort(404);

        $data = $request->except(['_token', '_method']);

        if (isset($data['subject_requirements']) && is_array($data['subject_requirements'])) {
            $data['subject_requirements'] = array_values($data['subject_requirements']);
        } else {
            unset($data['subject_requirements']);
        }

        try {
            $outcome = $this->opportunityService->editImpact($listing->opportunity_id, $data, $request->user());
        } catch (\Throwable) {
            return response()->json(['message' => 'Could not work this out yet.'], 422);
        }

        return response()->json(EditImpact::describe($outcome));
    }

    /**
     * Move a live listing's deadline later.
     *
     * Validated into a bag named for the dashboard dialog it came from. Every
     * listing has its own Extend dialog on one page; errors in the default bag
     * were shown in all of them, so a refusal for one listing appeared to be
     * about every listing. The same name is what lets the page reopen the dialog
     * that failed.
     */
    public function extendDeadline(Request $request, int $id)
    {
        $listing = $this->ownedListing($request, $id, 'extendDeadline');

        $rules = ['required', 'date', 'after:today'];

        // Not earlier than the date it already has: that would shorten it, which
        // is an edit (and a material one), not an extension.
        if ($listing?->deadline) {
            $rules[] = 'after_or_equal:' . $listing->deadline->toDateString();
        }

        $data = $request->validateWithBag('extend-deadline-' . $id, [
            'deadline' => $rules,
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'deadline.after_or_equal' => 'The new deadline cannot be earlier than the current one'
                . ($listing?->deadline ? ' (' . $listing->deadline->format('d M Y') . ').' : '.'),
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
        $this->ownedListing($request, $id, 'withdraw');

        $data = $request->validateWithBag('withdraw-' . $id, [
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
     * The provider's own listing, checked against OpportunityPolicy as well as
     * by ownership.
     *
     * findOwnedOrFail() is what actually scopes the query to the provider, and
     * stays the first line of defence: another provider's listing is never handed
     * to the policy at all. The policy is the second, stating the rule
     * in one place a future route cannot forget. A withdrawn listing skips the
     * policy check on purpose - the service refuses it with a message that says
     * so, which is more use to the provider than a bare 403.
     *
     * Returns null for a listing that is not theirs, and leaves the response to
     * the caller: the edit page answers 404, while the write actions pass on to
     * the service, which refuses with a flash message as it always has.
     */
    private function ownedListing(Request $request, int $id, string $ability): ?Opportunity
    {
        try {
            $opportunity = $this->opportunityService->findOwnedOrFail($id, $request->user());
        } catch (\RuntimeException) {
            return null;
        }

        if (! $opportunity->isWithdrawn()) {
            $this->authorize($ability, $opportunity);
        }

        return $opportunity;
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
}
