<?php

namespace App\Http\Controllers;

use App\Services\ApplicationService;
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
            'awards' => $this->applicationService->awardsByOpportunity($request->user()),
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
            'currencies' => FormOptions::CURRENCIES,
            'defaultCurrency' => FormOptions::DEFAULT_CURRENCY,
            'citizenships' => FormOptions::CITIZENSHIPS,
            'provinces' => FormOptions::ZIMBABWE_PROVINCES,
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
            'award_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'award_currency' => ['nullable', Rule::in(FormOptions::CURRENCIES)],
            'award_slots' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'is_renewable' => ['nullable', 'boolean'],
            'external_url' => ['nullable', 'url', 'max:500'],
            'min_academic_points' => ['nullable', 'integer', 'min:1', 'max:60'],
            'max_age' => ['nullable', 'integer', 'min:10', 'max:99'],
            'required_citizenship' => ['nullable', Rule::in(FormOptions::CITIZENSHIPS)],
            'required_province' => ['nullable', Rule::in(FormOptions::ZIMBABWE_PROVINCES)],
            'requires_results_certificate' => ['nullable', 'boolean'],
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
            'educationLevels' => FormOptions::educationLevelGroups(),
            'fields' => FormOptions::FIELDS_OF_STUDY,
            'countries' => FormOptions::COUNTRIES,
            'fundingTypes' => FormOptions::FUNDING_TYPES,
            'defaultCountry' => FormOptions::DEFAULT_COUNTRY,
            'targetFieldSuggestions' => $this->opportunityService->targetFields(),
            'awardingBodySuggestions' => $this->opportunityService->providerNames(),
            'currencies' => FormOptions::CURRENCIES,
            'defaultCurrency' => FormOptions::DEFAULT_CURRENCY,
            'citizenships' => FormOptions::CITIZENSHIPS,
            'provinces' => FormOptions::ZIMBABWE_PROVINCES,
        ]);
    }

    public function update(Request $request, int $id)
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
            'award_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'award_currency' => ['nullable', Rule::in(FormOptions::CURRENCIES)],
            'award_slots' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'is_renewable' => ['nullable', 'boolean'],
            'external_url' => ['nullable', 'url', 'max:500'],
            'min_academic_points' => ['nullable', 'integer', 'min:1', 'max:60'],
            'max_age' => ['nullable', 'integer', 'min:10', 'max:99'],
            'required_citizenship' => ['nullable', Rule::in(FormOptions::CITIZENSHIPS)],
            'required_province' => ['nullable', Rule::in(FormOptions::ZIMBABWE_PROVINCES)],
            'requires_results_certificate' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $opportunity = $this->opportunityService->update($id, $data, $request->user(), $data['reason']);
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
}
