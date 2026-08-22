<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OpportunityResource;
use App\Services\OpportunityService;
use App\Services\PlatformStatsService;
use Illuminate\Http\Request;

/** Read-only JSON surface used by the marketing site and integrations. */
class PublicApiController extends Controller
{
    public function __construct(
        private readonly OpportunityService $opportunityService,
        private readonly PlatformStatsService $platformStatsService,
    ) {
    }

    public function scholarships(Request $request)
    {
        $filters = $request->only([
            'keyword', 'education_level', 'country', 'field_of_study',
            'provider', 'funding_type', 'deadline_before',
        ]);

        $perPage = min(max((int) $request->query('per_page', 20), 1), 50);

        return OpportunityResource::collection($this->opportunityService->search($filters, $perPage));
    }

    public function scholarship(int $id)
    {
        $opportunity = $this->opportunityService->findPubliclyVisible($id);

        abort_if($opportunity === null, 404, 'Scholarship not found.');

        return new OpportunityResource($opportunity);
    }

    public function stats()
    {
        return response()->json($this->platformStatsService->publicStats());
    }
}
