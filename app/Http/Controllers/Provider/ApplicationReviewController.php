<?php

namespace App\Http\Controllers\Provider;

use App\Exceptions\InvalidApplicationTransition;
use App\Http\Controllers\Controller;
use App\Services\ApplicationService;
use App\Support\ApplicationStateMachine;
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
            // Tabs, not review targets: Awarded is something to filter by but
            // never something the review form or the bulk action can set.
            'statuses' => ApplicationStatus::FILTERABLE,
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
            // Only the moves this application can actually make. A decided or
            // withdrawn one yields an empty list, which is what collapses the
            // decision form - offering a dropdown whose every option would be
            // refused on save is worse than offering none.
            //
            // Intersected with REVIEWABLE because awarding is not one of the
            // moves this form posts: review() validates against REVIEWABLE, so
            // an "Awarded" option in the dropdown would fail validation on save.
            // It gets its own button instead, driven by canAward below.
            'statuses' => array_values(array_intersect(
                ApplicationStateMachine::allowedFor(
                    $application->application_status,
                    ApplicationStateMachine::ACTOR_PROVIDER
                ),
                ApplicationStatus::REVIEWABLE
            )),
            'canAward' => $application->canBeAwarded(),
            'timeline' => ApplicationStatus::timeline($application->application_status),
            'awaitingResponse' => $application->awaitsApplicantResponse(),
        ]);
    }

    /**
     * The award itself: the provider grants the scholarship to an applicant they
     * have already approved.
     *
     * No request body - there is nothing to decide beyond who, and that is the
     * URL. Everything else (ownership, the transition, the timestamp, the audit
     * line, the notification) is the service's, so this path cannot drift away
     * from the rules the rest of the lifecycle follows.
     */
    public function award(Request $request, int $id)
    {
        try {
            $this->applicationService->award($id, $request->user());
        } catch (InvalidApplicationTransition $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('provider.applications.show', $id)
            ->with('successMessage', 'Scholarship awarded. The applicant has been notified.');
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

        try {
            $this->applicationService->updateStatus(
                $id,
                $data['status'],
                $data['reason'] ?? null,
                $request->user(),
                $data['interview_at'] ?? null
            );
        } catch (InvalidApplicationTransition $e) {
            // A refused move is a business answer, not a crash: the provider is
            // told which rule stopped them and the page keeps their input.
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('provider.applications.show', $id)
            ->with('successMessage', 'Application updated to ' . ApplicationStatus::displayLabel($data['status']) . '.');
    }
}
