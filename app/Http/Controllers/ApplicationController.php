<?php

namespace App\Http\Controllers;

use App\Services\ApplicantProfileService;
use App\Services\ApplicationService;
use App\Services\CalendarService;
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
        private readonly CalendarService $calendarService,
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

        $profile = $this->profileService->forUser($user);

        return view('applications.wizard', [
            'opportunity' => $opportunity,
            'profile' => $profile,
            'fit' => $this->recommendationService->scoreOne($user, $opportunity),
            'missingDocumentTypes' => $profile->missingRequiredDocumentTypes(),
        ]);
    }

    public function submit(Request $request, int $opportunityId)
    {
        // Recomputed server-side rather than trusted from the form, so a
        // profile document uploaded in another tab can't be bypassed.
        $missingDocumentTypes = $this->profileService->forUser($request->user())->missingRequiredDocumentTypes();

        $rules = [
            'personal_statement' => ['required', 'string', 'min:100', 'max:5000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'confirm' => ['accepted'],
        ];
        foreach ($missingDocumentTypes as $type) {
            $rules["documents.$type"] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'];
        }

        $data = $request->validate($rules);

        foreach ($missingDocumentTypes as $type) {
            if ($request->hasFile("documents.$type")) {
                $this->profileService->storeDocument($request->user(), $type, $request->file("documents.$type"));
            }
        }

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

    /**
     * The applicant pulls out of a scholarship they applied to.
     *
     * A confirmation is required in the form rather than here; by the time the
     * request arrives the decision has been made, so the only job left is to
     * report why it could not be honoured, if it could not.
     */
    public function withdraw(Request $request, int $applicationId)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->applicationService->withdraw($applicationId, $request->user(), $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->route('applications.mine')
            ->with('successMessage', 'Your application was withdrawn and the provider notified.');
    }

    /** Answers a provider's question or document request without leaving the page. */
    public function respondToInfoRequest(Request $request, int $applicationId)
    {
        $data = $request->validate([
            'response' => ['required', 'string', 'min:10', 'max:3000'],
        ]);

        try {
            $this->applicationService->respondToInfoRequest($applicationId, $request->user(), $data['response']);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', 'Your response was sent to the provider.');
    }

    /**
     * The scheduled interview as a calendar file.
     *
     * Served as a download rather than a link to a hosted calendar so it works
     * on a phone with no account signed in - which is how most students here
     * will open it.
     */
    public function interviewCalendar(Request $request, int $applicationId)
    {
        $application = $this->applicationService->findForApplicant($applicationId, $request->user());

        try {
            $ics = $this->calendarService->interviewInvite($application);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $this->calendarService->filename($application) . '"',
        ]);
    }

    public function confirmation(Request $request, int $applicationId)
    {
        $application = $this->applicationService->findForApplicant($applicationId, $request->user());

        return view('applications.confirmation', [
            'application' => $application,
            'timeline' => ApplicationStatus::timeline($application->application_status),
            'awaitingResponse' => $application->awaitsApplicantResponse(),
        ]);
    }
}
