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

    public function create(Request $request)
    {
        return view('opportunities.create', $this->formData() + [
            // What a new listing starts with: the currency, province and (confirmed)
            // website this provider used last time. See ListingDefaults.
            'defaults' => \App\Support\ListingDefaults::forProvider($request->user()),
        ]);
    }

    /**
     * The create form, filled from an earlier listing of the provider's.
     *
     * A copy is a NEW listing: the form posts to the ordinary create action, so it
     * is validated, risk-checked and routed to review (or, for a trusted provider,
     * published) exactly like one typed from scratch. What is copied is decided by
     * ListingTemplate, which takes what the provider wrote and nothing that
     * happened to the original.
     */
    public function duplicate(Request $request, int $id)
    {
        $source = $this->ownedListing($request, $id, 'update') ?? abort(404);

        return view('opportunities.create', $this->formData() + [
            'defaults' => [],
            'prefill' => \App\Support\ListingTemplate::copyOf($source->load('subjectRequirements.qualification', 'subjectRequirements.subject')),
            'duplicateOf' => $source,
        ]);
    }

    /**
     * Save the form as a draft: only a title is required, and whatever else can be
     * stored is. What cannot is listed afterwards, never dropped silently.
     */
    public function saveDraft(Request $request)
    {
        $this->authorize('create', Opportunity::class);

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ], [
            'title.required' => 'Give the draft a title, so you can find it again.',
        ]);

        try {
            $result = app(\App\Services\ListingDraftService::class)->save(
                $request->all(),
                $request->user(),
                $request->filled('draft_id') ? (int) $request->input('draft_id') : null
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        $redirect = redirect()
            ->route('opportunities.edit', $result['draft']->opportunity_id)
            ->with('successMessage', 'Draft saved. Only you can see it, and nobody has been told.');

        return $result['notKept'] === [] ? $redirect : $redirect->with('draftNotKept', $result['notKept']);
    }

    public function discardDraft(Request $request, int $id)
    {
        $draft = Opportunity::query()
            ->whereKey($id)
            ->where('provider_user_id', $request->user()->user_id)
            ->first() ?? abort(404);

        $this->authorize('discardDraft', $draft);

        app(\App\Services\ListingDraftService::class)->discard($draft, $request->user());

        return redirect()->route('provider.dashboard')->with('successMessage', 'Draft discarded.');
    }

    /**
     * Tell the provider, on the page they land on, where the listing's words disagree
     * with its own settings. It is saved either way - the settings win, and the
     * moderator sees it - but a provider who can fix it should know now.
     *
     * @param  array<string, mixed>  $input
     */
    private function withListingWarnings(\Illuminate\Http\RedirectResponse $response, array $input): \Illuminate\Http\RedirectResponse
    {
        $warnings = \App\Services\ScholarFit\DescriptionConflicts::messages($input);

        return $warnings === [] ? $response : $response->with('listingWarnings', $warnings);
    }

    /**
     * The public page for the form as it stands, in a new tab. Nothing is saved or
     * announced: the listing is built in memory from whatever has been typed, and
     * forgivingly, because the point is to look before the form is complete.
     */
    public function preview(Request $request)
    {
        $listing = \App\Support\ListingPreview::build(
            $request->except(['_token', '_method']),
            $request->user()
        );

        return view('public.detail', [
            'opportunity' => $listing,
            'preview' => true,
            'isSaved' => false,
            'hasApplied' => false,
            'fit' => null,
            'related' => collect(),
            'appliedIds' => [],
            'accepted' => [],
            'acceptedApplication' => null,
            // Where the words disagree with the settings, shown on the preview so it can be
            // fixed before submitting rather than after.
            'conflicts' => \App\Services\ScholarFit\DescriptionConflicts::detect($listing),
            // The rules the engine will apply (settings and the words alike) and how many
            // applicants meet them today - a number only from the privacy threshold up.
            'reading' => [
                'rules' => \App\Services\ScholarFit\ListingReading::rules($listing),
                'notes' => \App\Services\ScholarFit\ListingReading::notes($listing),
                'count' => \App\Services\ScholarFit\ListingReading::displayCount(\App\Services\ScholarFit\ListingReading::matchingCount($listing)),
                'minimum' => \App\Services\ScholarFit\ListingReading::minimum(),
            ],
        ]);
    }

    /** What the listing form needs to render, shared by create and duplicate. */
    private function formData(): array
    {
        return [
            'educationLevels' => FormOptions::providerLevelGroups(forTarget: true),
            'minimumLevels' => FormOptions::providerLevelGroups(forTarget: false),
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
            'catalogue' => app(\App\Services\Catalogue\ProgrammeCatalogue::class)->formOptions(request()->user()),
        ];
    }
    public function store(StoreOpportunityRequest $request)
    {
        $this->authorize('create', Opportunity::class);

        // A draft being submitted arrives with its id. It must be this provider's own
        // draft: the submit path must not be a way to overwrite a live listing, or someone
        // else's.
        $draft = null;

        if ($request->filled('draft_id')) {
            $draft = Opportunity::query()
                ->whereKey((int) $request->input('draft_id'))
                ->where('provider_user_id', $request->user()->user_id)
                ->first();

            abort_if($draft === null || ! $draft->isDraft(), 404);
        }

        try {
            $this->opportunityService->create($request->validated(), $request->user(), $draft);
        } catch (UnauthorizedException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        return $this->withListingWarnings(
            redirect()
                ->route('provider.dashboard')
                ->with('successMessage', 'Scholarship submitted for review. It goes live once an administrator approves it.'),
            $request->validated()
        );
    }

    public function edit(Request $request, int $id)
    {
        $opportunity = $this->ownedListing($request, $id, 'update') ?? abort(404);

        // A draft has no review history to explain and no live listing to protect, so it
        // is edited in the create form it came from; submitting it goes through the
        // ordinary submit.
        if ($opportunity->isDraft()) {
            return view('opportunities.create', $this->formData() + [
                'defaults' => [],
                'prefill' => $opportunity->load('subjectRequirements.qualification', 'subjectRequirements.subject'),
                'draft' => $opportunity,
            ]);
        }

        if ($opportunity->isWithdrawn()) {
            return redirect()
                ->route('provider.dashboard')
                ->with('errorMessage', 'This scholarship has been withdrawn and can no longer be edited.');
        }

        return view('opportunities.edit', [
            'opportunity' => $opportunity,
            'educationLevels' => FormOptions::providerLevelGroups(forTarget: true),
            'minimumLevels' => FormOptions::providerLevelGroups(forTarget: false),
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
            'catalogue' => app(\App\Services\Catalogue\ProgrammeCatalogue::class)->formOptions($request->user()),
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

        return $this->withListingWarnings(
            redirect()
                ->route('provider.dashboard')
                ->with('successMessage', '"' . $opportunity->title . '" was updated and re-submitted for review.'),
            $data
        );
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

        // What saving would do includes the clearing saving would do.
        if (! empty($data['level_driven']) && in_array($data['education_level'] ?? null, \App\Support\EducationLevel::TARGET_LEVELS, true)) {
            $data = \App\Support\OpportunityLevelRules::clearInapplicable($data, $data['education_level']) + $data;
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
