<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicController;
use App\Services\SavedSearchService;
use Illuminate\Http\Request;

class SavedSearchController extends Controller
{
    public function __construct(private readonly SavedSearchService $savedSearchService)
    {
    }

    public function index(Request $request)
    {
        return view('applicant.saved-searches', [
            'searches' => $this->savedSearchService->forUser($request->user()),
            'maxSearches' => SavedSearchService::MAX_PER_USER,
        ]);
    }

    /**
     * Saves the filters the student is currently looking at.
     *
     * The filters come from the same reader the browse page uses, so what gets
     * stored is exactly the search they were shown - not a re-typed
     * approximation of it.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'alerts_enabled' => ['nullable', 'boolean'],
        ]);

        $filters = PublicController::filtersFrom($request);

        $this->savedSearchService->create(
            $request->user(),
            $data['name'],
            $filters,
            $request->boolean('alerts_enabled', true)
        );

        return back()->with(
            'successMessage',
            'Search saved. We will alert you when a new scholarship matches it.'
        );
    }

    public function toggle(Request $request, int $id)
    {
        $search = $this->savedSearchService->toggleAlerts($id, $request->user());

        return back()->with('successMessage', $search->alerts_enabled
            ? 'Alerts turned on for "' . $search->name . '".'
            : 'Alerts turned off for "' . $search->name . '".');
    }

    public function destroy(Request $request, int $id)
    {
        $this->savedSearchService->delete($id, $request->user());

        return back()->with('successMessage', 'Saved search deleted.');
    }
}
