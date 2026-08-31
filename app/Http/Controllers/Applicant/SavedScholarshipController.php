<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Services\ApplicationService;
use App\Services\SavedScholarshipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SavedScholarshipController extends Controller
{
    public function __construct(
        private readonly SavedScholarshipService $savedScholarshipService,
        private readonly ApplicationService $applicationService,
    ) {
    }

    public function index(Request $request)
    {
        return view('applicant.saved', [
            'saved' => $this->savedScholarshipService->listSaved($request->user()),
            'appliedIds' => $this->applicationService->appliedIds($request->user()),
            'awards' => $this->applicationService->awardsByOpportunity($request->user()),
        ]);
    }

    public function store(Request $request, int $id)
    {
        try {
            $this->savedScholarshipService->save($request->user(), $id);
            $message = ['successMessage', 'Scholarship saved.'];
        } catch (\Throwable $e) {
            Log::warning('Save scholarship failed', ['user' => $request->user()->email, 'error' => $e->getMessage()]);
            $message = ['errorMessage', 'Could not save scholarship. Please try again.'];
        }

        return back()->with(...$message);
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $this->savedScholarshipService->remove($request->user(), $id);
            $message = ['successMessage', 'Removed from saved list.'];
        } catch (\Throwable $e) {
            Log::warning('Remove saved scholarship failed', ['user' => $request->user()->email, 'error' => $e->getMessage()]);
            $message = ['errorMessage', 'Could not remove saved scholarship.'];
        }

        return back()->with(...$message);
    }
}
