<?php

namespace App\Http\Controllers;

use App\Services\ApplicationService;
use App\Services\OpportunityService;
use App\Services\PlatformStatsService;
use App\Services\RecommendationService;
use App\Services\SavedScholarshipService;
use App\Support\FormOptions;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function __construct(
        private readonly PlatformStatsService $platformStatsService,
        private readonly OpportunityService $opportunityService,
        private readonly SavedScholarshipService $savedScholarshipService,
        private readonly RecommendationService $recommendationService,
        private readonly ApplicationService $applicationService,
    ) {
    }

    public function landing(Request $request)
    {
        return view('public.index', [
            'stats' => $this->platformStatsService->publicStats(),
            'featured' => $this->opportunityService->featured(6),
            'fields' => FormOptions::FIELDS_OF_STUDY,
            // The featured cards carry the same save button as the browse page,
            // so they need the same saved list behind it - without it every card
            // renders as unsaved and its button posts to the store route, so a
            // student cannot unsave from here and re-saving is the only outcome.
            'savedIds' => $this->savedScholarshipService->savedIds($request->user()),
            'appliedIds' => $this->applicationService->appliedIds($request->user()),
            // An award is a subset of "applied", and the cards say which subset:
            // "Applied" on a scholarship the student has actually won reads as
            // though nothing has happened yet.
            'accepted' => $this->applicationService->acceptedByOpportunity($request->user()),
        ]);
    }

    public function scholarships(Request $request)
    {
        $filters = $this->filters($request);

        return view('public.scholarships', [
            'stats' => $this->platformStatsService->publicStats(),
            'opportunities' => $this->opportunityService->search($filters),
            'providerNames' => $this->opportunityService->providerNames(),
            'targetFields' => $this->opportunityService->targetFields(),
            'filters' => $filters,
            'savedIds' => $this->savedScholarshipService->savedIds($request->user()),
            'appliedIds' => $this->applicationService->appliedIds($request->user()),
            'accepted' => $this->applicationService->acceptedByOpportunity($request->user()),
        ]);
    }

    public function detail(Request $request, int $id)
    {
        $opportunity = $this->opportunityService->findPubliclyVisible($id);

        abort_if($opportunity === null, 404, 'Scholarship not found.');

        $user = $request->user();

        // The provider's funnel starts here. A provider looking at their own post
        // is not an audience, so their visit is not counted.
        if ($user?->user_id !== $opportunity->provider_user_id) {
            $this->opportunityService->recordView($opportunity);
        }

        $accepted = $this->applicationService->acceptedByOpportunity($user);

        return view('public.detail', [
            'opportunity' => $opportunity,
            'isSaved' => $this->savedScholarshipService->isSaved($user, $id),
            'hasApplied' => $user && $user->isApplicant() && $this->applicationService->hasApplied($user, $id),
            // The fit panel only renders for signed-in students with a profile.
            'fit' => $user && $user->isApplicant()
                ? $this->recommendationService->scoreOne($user, $opportunity)
                : null,
            'related' => $this->opportunityService->searchAll([
                'field_of_study' => $opportunity->target_field,
            ])->where('opportunity_id', '!=', $id)->take(3),
            'appliedIds' => $this->applicationService->appliedIds($user),
            'accepted' => $accepted,
            // The award for this listing specifically, which is what replaces
            // Apply and Quick apply on the page for the student who holds it.
            'acceptedApplication' => $accepted[$id] ?? null,
        ]);
    }

    /**
     * Shared shape for the faceted search on both listing pages.
     *
     * An unknown sort key is dropped here rather than passed down, so the select
     * always renders with a value it actually offers.
     *
     * Read with input() rather than query(): the browse pages send these on the
     * query string, but "save this search" POSTs the very same set as hidden
     * fields, and both must produce the identical filter array.
     */
    public static function filtersFrom(Request $request): array
    {
        $sort = (string) $request->input('sort', FormOptions::DEFAULT_SORT);

        return [
            'keyword' => $request->input('keyword'),
            'education_level' => $request->input('education_level'),
            'country' => $request->input('country'),
            'field_of_study' => $request->input('field_of_study'),
            'provider' => $request->input('provider'),
            'funding_type' => $request->input('funding_type'),
            'deadline_before' => $request->input('deadline_before'),
            'min_award' => $request->input('min_award'),
            'renewable_only' => $request->boolean('renewable_only') ?: null,
            'sort' => array_key_exists($sort, FormOptions::SORT_OPTIONS) ? $sort : FormOptions::DEFAULT_SORT,
        ];
    }

    private function filters(Request $request): array
    {
        return self::filtersFrom($request);
    }
}
