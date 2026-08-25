<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Services\ApplicationService;
use App\Support\ApplicationStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApplicationReviewController extends Controller
{
    public function __construct(private readonly ApplicationService $applicationService)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        return view('applications.provider-applications', [
            'applications' => $this->applicationService->forProvider($user, $request->query('status')),
            'statusCounts' => $this->applicationService->statusCountsForProvider($user),
            'activeStatus' => $request->query('status'),
            'statuses' => ApplicationStatus::REVIEWABLE,
        ]);
    }

    public function show(Request $request, int $id)
    {
        $application = $this->applicationService->findForProvider($id, $request->user());

        return view('applications.provider-review', [
            'application' => $application,
            'applicantProfile' => $application->user?->applicantProfile,
            'statuses' => ApplicationStatus::REVIEWABLE,
            'timeline' => ApplicationStatus::timeline($application->application_status),
        ]);
    }

    /** Single entry point for every provider decision on an application. */
    public function review(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ApplicationStatus::REVIEWABLE)],
            'reason' => ['nullable', 'string', 'max:500'],
            'interview_at' => ['required_if:status,' . ApplicationStatus::INTERVIEW, 'nullable', 'date'],
        ]);

        $this->applicationService->updateStatus(
            $id,
            $data['status'],
            $data['reason'] ?? null,
            $request->user(),
            $data['interview_at'] ?? null
        );

        return redirect()
            ->route('provider.applications.show', $id)
            ->with('successMessage', 'Application updated to ' . ApplicationStatus::displayLabel($data['status']) . '.');
    }
}
