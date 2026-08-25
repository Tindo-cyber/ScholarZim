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
            // Interviews need a per-applicant date, so they are not offered in bulk.
            'bulkStatuses' => array_values(array_diff(
                ApplicationStatus::REVIEWABLE,
                [ApplicationStatus::INTERVIEW]
            )),
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
            'awaitingResponse' => $application->awaitsApplicantResponse(),
        ]);
    }

    /**
     * The same decision across a selection from the inbox.
     *
     * Partial success is reported rather than hidden: a batch where three of
     * five moved is more useful to a provider than a flat "done", and the two
     * that did not are named.
     */
    public function bulkReview(Request $request)
    {
        $data = $request->validate([
            'applications' => ['required', 'array', 'min:1'],
            'applications.*' => ['integer'],
            'status' => ['required', Rule::in(ApplicationStatus::REVIEWABLE)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->applicationService->bulkUpdateStatus(
            $data['applications'],
            $data['status'],
            $data['reason'] ?? null,
            $request->user()
        );

        $message = $result['updated'] . ' application(s) set to '
            . ApplicationStatus::displayLabel($data['status']) . '.';

        if ($result['failed'] !== []) {
            return back()
                ->with('successMessage', $message)
                ->with('errorMessage', 'Skipped: ' . implode('; ', $result['failed']));
        }

        return back()->with('successMessage', $message);
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
