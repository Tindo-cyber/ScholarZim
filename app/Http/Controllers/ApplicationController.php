<?php

namespace App\Http\Controllers;

use App\Services\ApplicantProfileService;
use App\Services\ApplicationService;
use App\Services\OpportunityService;
use App\Services\RecommendationService;
use App\Support\ApplicationStatus;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicationService $applicationService,
        private readonly OpportunityService $opportunityService,
        private readonly ApplicantProfileService $profileService,
        private readonly RecommendationService $recommendationService,
    ) {
    }

    public function myApplications(Request $request)
    {
        $user = $request->user();

        return view('applications.my-applications', [
            'applications' => $this->applicationService->paginateForApplicant($user, $request->query('status')),
            'statusCounts' => $this->applicationService->statusCountsForApplicant($user),
            'activeStatus' => $request->query('status'),
            'statuses' => ApplicationStatus::REVIEWABLE,
        ]);
    }

    /** The apply wizard: profile recap, fit breakdown, statement, document. */
    public function showWizard(Request $request, int $opportunityId)
    {
        $opportunity = $this->opportunityService->findPubliclyVisible($opportunityId);
        abort_if($opportunity === null, 404, 'Scholarship not found.');

        $user = $request->user();

        if ($this->applicationService->hasApplied($user, $opportunityId)) {
            return redirect()
                ->route('applications.mine')
                ->with('errorMessage', 'You have already applied to this scholarship.');
        }

        return view('applications.wizard', [
            'opportunity' => $opportunity,
            'profile' => $this->profileService->forUser($user),
            'fit' => $this->recommendationService->scoreOne($user, $opportunity),
        ]);
    }

    public function submit(Request $request, int $opportunityId)
    {
        $data = $request->validate([
            'personal_statement' => ['required', 'string', 'min:100', 'max:5000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'confirm' => ['accepted'],
        ]);

        try {
            $application = $this->applicationService->submit(
                $opportunityId,
                $request->user(),
                $data,
                $request->file('document')
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('errorMessage', $e->getMessage());
        }

        return redirect()->route('applications.confirmation', $application->application_id);
    }

    /** One-click apply straight from a listing card. */
    public function quickApply(Request $request, int $opportunityId)
    {
        try {
            $application = $this->applicationService->quickApply($opportunityId, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('applications.confirmation', $application->application_id)
            ->with('successMessage', 'Application submitted using your profile details.');
    }

    public function confirmation(Request $request, int $applicationId)
    {
        $application = $this->applicationService->findForApplicant($applicationId, $request->user());

        return view('applications.confirmation', [
            'application' => $application,
            'timeline' => ApplicationStatus::timeline($application->application_status),
        ]);
    }
}
