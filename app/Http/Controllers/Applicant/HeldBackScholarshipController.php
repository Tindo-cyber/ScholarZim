<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Services\ApplicationService;
use App\Services\HeldBackScholarshipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HeldBackScholarshipController extends Controller
{
    public function __construct(
        private readonly HeldBackScholarshipService $heldBackScholarshipService,
        private readonly ApplicationService $applicationService,
    ) {
    }

    public function index(Request $request)
    {
        return view('applicant.held-back', [
            'heldBack' => $this->heldBackScholarshipService->listHeldBack($request->user()),
            'appliedIds' => $this->applicationService->appliedIds($request->user()),
            'accepted' => $this->applicationService->acceptedByOpportunity($request->user()),
        ]);
    }

    public function store(Request $request, int $id)
    {
        try {
            $this->heldBackScholarshipService->hold($request->user(), $id);
            $message = ['successMessage', 'Scholarship held back. It will no longer show as an active match.'];
        } catch (\Throwable $e) {
            Log::warning('Hold back scholarship failed', ['user' => $request->user()->email, 'error' => $e->getMessage()]);
            $message = ['errorMessage', 'Could not hold back scholarship. Please try again.'];
        }

        return back()->with(...$message);
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $this->heldBackScholarshipService->release($request->user(), $id);
            $message = ['successMessage', 'Removed from held back. It can appear as an active match again.'];
        } catch (\Throwable $e) {
            Log::warning('Release held back scholarship failed', ['user' => $request->user()->email, 'error' => $e->getMessage()]);
            $message = ['errorMessage', 'Could not remove from held back.'];
        }

        return back()->with(...$message);
    }
}
