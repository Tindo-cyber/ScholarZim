<?php

namespace App\Http\Controllers\Provider;

use App\Exceptions\InvalidApplicationTransition;
use App\Http\Controllers\Controller;
use App\Services\ApplicationService;
use App\Services\RecommendationService;
use App\Support\ApplicationStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApplicationReviewController extends Controller
{
    public function __construct(
        private readonly ApplicationService $applicationService,
        private readonly RecommendationService $recommendationService,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        return view('applications.provider-applications', [
            'applications' => $this->applicationService->forProvider($user, $request->query('status')),
            'statusCounts' => $this->applicationService->statusCountsForProvider($user),
            'activeStatus' => $request->query('status'),
            'statuses' => ApplicationStatus::FILTERABLE,
        ]);
    }

    public function show(Request $request, int $id)
    {
        $application = $this->applicationService->findForProvider($id, $request->user());

        return view('applications.provider-review', [
            'application' => $application,
            'applicantProfile' => $application->user?->applicantProfile,
            // Guidance for the reviewer, not an input to the decision: how well
            // this applicant's profile lines up with what the listing asks for.
            // Null when either side of the comparison is missing.
            'fit' => $application->user && $application->opportunity
                ? $this->recommendationService->scoreOne($application->user, $application->opportunity)
                : null,
            // A decided or withdrawn application yields false, which collapses
            // the decision form - offering buttons whose every outcome would be
            // refused on save is worse than offering none.
            'canDecide' => $application->awaitsDecision(),
            'timeline' => ApplicationStatus::timeline($application->application_status),
        ]);
    }

    /**
     * The provider's decision: accept or reject, with a reason the applicant
     * reads verbatim. There is nothing to do afterwards - accepting an
     * application is granting the scholarship.
     */
    public function review(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ApplicationStatus::DECISIONS)],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->applicationService->decide(
                $id,
                $data['status'],
                $data['reason'],
                $request->user()
            );
        } catch (InvalidApplicationTransition $e) {
            // A refused move is a business answer, not a crash: the provider is
            // told which rule stopped them and the page keeps their input.
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('provider.applications.show', $id)
            ->with('successMessage', 'Application ' . strtolower(ApplicationStatus::displayLabel($data['status']))
                . '. The applicant has been notified.');
    }
}
