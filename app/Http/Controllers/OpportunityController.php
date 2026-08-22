<?php

namespace App\Http\Controllers;

use App\Services\OpportunityService;
use App\Services\SavedScholarshipService;
use App\Support\FormOptions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\UnauthorizedException;

class OpportunityController extends Controller
{
    public function __construct(
        private readonly OpportunityService $opportunityService,
        private readonly SavedScholarshipService $savedScholarshipService,
    ) {
    }

    /** Signed-in browse view; same data as the public list plus save state. */
    public function index(Request $request)
    {
        $filters = [
            'keyword' => $request->query('keyword'),
            'education_level' => $request->query('education_level'),
            'country' => $request->query('country'),
            'field_of_study' => $request->query('field_of_study'),
            'provider' => $request->query('provider'),
            'funding_type' => $request->query('funding_type'),
            'deadline_before' => $request->query('deadline_before'),
        ];

        return view('opportunities.list', [
            'opportunities' => $this->opportunityService->search($filters),
            'providerNames' => $this->opportunityService->providerNames(),
            'targetFields' => $this->opportunityService->targetFields(),
            'filters' => $filters,
            'savedIds' => $this->savedScholarshipService->savedIds($request->user()),
        ]);
    }

    public function create()
    {
        return view('opportunities.create', [
            'educationLevels' => FormOptions::educationLevelGroups(),
            'fields' => FormOptions::FIELDS_OF_STUDY,
            'countries' => FormOptions::COUNTRIES,
            'fundingTypes' => FormOptions::FUNDING_TYPES,
            'defaultCountry' => FormOptions::DEFAULT_COUNTRY,
            'targetFieldSuggestions' => $this->opportunityService->targetFields(),
            'awardingBodySuggestions' => $this->opportunityService->providerNames(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'provider_display_name' => ['nullable', 'string', 'max:255'],
            'education_level' => ['nullable', Rule::in(FormOptions::educationLevels())],
            'target_field' => ['nullable', 'string', 'max:255'],
            'funding_type' => ['nullable', Rule::in(FormOptions::FUNDING_TYPES)],
            'country' => ['nullable', 'string', 'max:100'],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        try {
            $this->opportunityService->create($data, $request->user());
        } catch (UnauthorizedException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('provider.dashboard')
            ->with('successMessage', 'Scholarship submitted for review. It goes live once an administrator approves it.');
    }
}
