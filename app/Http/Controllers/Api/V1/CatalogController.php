<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OpportunityResource;
use App\Services\OpportunityService;
use App\Services\PlatformStatsService;
use App\Support\FormOptions;
use Illuminate\Http\Request;

/**
 * v1 of the public catalogue.
 *
 * Everything here reads through OpportunityService::search(), which applies
 * scopePubliclyVisible() - so the API can only ever return what an anonymous
 * visitor could already see on the site. There is no separate visibility rule to
 * keep in step.
 */
class CatalogController extends Controller
{
    private const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly OpportunityService $opportunityService,
        private readonly PlatformStatsService $platformStatsService,
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->only([
            'keyword', 'education_level', 'country', 'field_of_study',
            'provider', 'funding_type', 'deadline_before', 'min_award',
        ]);

        $sort = (string) $request->query('sort', FormOptions::DEFAULT_SORT);
        $filters['sort'] = array_key_exists($sort, FormOptions::SORT_OPTIONS) ? $sort : FormOptions::DEFAULT_SORT;

        $perPage = min(max((int) $request->query('per_page', 20), 1), self::MAX_PER_PAGE);

        return OpportunityResource::collection($this->opportunityService->search($filters, $perPage))
            ->additional(['meta' => ['sort' => $filters['sort']]]);
    }

    public function show(int $id)
    {
        $opportunity = $this->opportunityService->findPubliclyVisible($id);

        abort_if($opportunity === null, 404, 'Scholarship not found.');

        return new OpportunityResource($opportunity);
    }

    public function stats()
    {
        return response()->json($this->platformStatsService->publicStats());
    }

    /** The facet values a client needs to build its own filter UI. */
    public function facets()
    {
        return response()->json([
            'educationLevels' => FormOptions::educationLevels(),
            'fieldsOfStudy' => FormOptions::FIELDS_OF_STUDY,
            'fundingTypes' => FormOptions::FUNDING_TYPES,
            'countries' => FormOptions::COUNTRIES,
            'currencies' => FormOptions::CURRENCIES,
            'providers' => $this->opportunityService->providerNames(),
            'targetFields' => $this->opportunityService->targetFields(),
            'sorts' => FormOptions::SORT_OPTIONS,
        ]);
    }
}
