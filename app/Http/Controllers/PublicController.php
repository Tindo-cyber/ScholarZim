<?php

namespace App\Http\Controllers;

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
    ) {
    }

    public function landing()
    {
        return view('public.index', [
            'stats' => $this->platformStatsService->publicStats(),
            'featured' => $this->opportunityService->featured(6),
            'fields' => FormOptions::FIELDS_OF_STUDY,
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
        ]);
    }

    public function detail(Request $request, int $id)
    {
        $opportunity = $this->opportunityService->findPubliclyVisible($id);

        abort_if($opportunity === null, 404, 'Scholarship not found.');

        $user = $request->user();

        return view('public.detail', [
            'opportunity' => $opportunity,
            'isSaved' => $this->savedScholarshipService->isSaved($user, $id),
            // The fit panel only renders for signed-in students with a profile.
            'fit' => $user && $user->isApplicant()
                ? $this->recommendationService->scoreOne($user, $opportunity)
                : null,
            'related' => $this->opportunityService->searchAll([
                'field_of_study' => $opportunity->target_field,
            ])->where('opportunity_id', '!=', $id)->take(3),
        ]);
    }

    /** Shared shape for the faceted search on both listing pages. */
    private function filters(Request $request): array
    {
        return [
            'keyword' => $request->query('keyword'),
            'education_level' => $request->query('education_level'),
            'country' => $request->query('country'),
            'field_of_study' => $request->query('field_of_study'),
            'provider' => $request->query('provider'),
            'funding_type' => $request->query('funding_type'),
            'deadline_before' => $request->query('deadline_before'),
        ];
    }
}
