<?php

namespace App\Http\Controllers\Provider;

use App\Exceptions\AwardsFilled;
use App\Exceptions\InvalidApplicationTransition;
use App\Http\Controllers\Controller;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Services\ApplicationService;
use App\Services\FileStorageService;
use App\Services\RecommendationService;
use App\Support\ApplicationStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApplicationReviewController extends Controller
{
    public function __construct(
        private readonly ApplicationService $applicationService,
        private readonly RecommendationService $recommendationService,
        private readonly FileStorageService $fileStorage,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        // A repeated key (?status[]=x) arrives as an array; only plain text is a filter.
        $status = is_string($request->query('status')) ? $request->query('status') : null;

        return view('applications.provider-applications', [
            'applications' => $this->applicationService->forProvider($user, $status),
            'statusCounts' => $this->applicationService->statusCountsForProvider($user),
            'activeStatus' => $status,
            'statuses' => ApplicationStatus::FILTERABLE,
        ]);
    }

    public function show(Request $request, int $id)
    {
        $application = $this->applicationService->findForProvider($id, $request->user());

        // Opening this page is what "reviewing" means from the applicant's
        // side - see ApplicationService::markViewedByProvider(). A no-op
        // past the first open, or on anything already decided.
        $this->applicationService->markViewedByProvider($application, $request->user());

        $fit = $application->user && $application->opportunity
            ? $this->recommendationService->evaluateOne($application->user, $application->opportunity)
            : null;
        $sentEligibility = $application->submittedEligibility();
        // Only said when the live check now gives a different answer from the one saved at submission.
        $profileChanged = $sentEligibility !== null && $fit !== null
            && ! \App\Services\ApplicationSnapshot::sameVerdict(\App\Services\ApplicationSnapshot::eligibility($fit), $sentEligibility);

        return view('applications.provider-review', [
            'application' => $application,
            'sentEligibility' => $sentEligibility,
            'profileChanged' => $profileChanged,
            'applicantProfile' => $application->user?->applicantProfile,
            // Guidance for the reviewer, not an input to the decision: how well
            // this applicant's profile lines up with what the listing asks for.
            // Null when either side of the comparison is missing.
            'fit' => $fit,
            // A decided or withdrawn application yields false, which collapses
            // the decision form - offering buttons whose every outcome would be
            // refused on save is worse than offering none.
            'canDecide' => $application->awaitsDecision(),
            'decisionHistory' => $application->decisionHistory(),
            'documents' => $this->documentRows($application),
            'timeline' => ApplicationStatus::timeline($application->application_status, $application->viewed_by_provider_at),
        ]);
    }

    /**
     * Every document this provider may open for this application, as the page lists it.
     *
     * One row for each document the application was submitted with, plus a "Not provided" row for any the
     * applicant's level asks for and did not supply, so "they did not send it" is told apart from "I cannot open
     * it". A document that was recorded but whose file cannot be found is shown as unavailable rather than offered
     * as a link that would 404, and no storage path is ever put in the row.
     *
     * @return array<int, array{label: string, state: string, route: ?string}>  state: available, unavailable, missing
     */
    private function documentRows(Application $application): array
    {
        $routes = [
            'results' => 'files.applicantResults',
            'grade7_slip' => 'files.applicantGrade7Slip',
            'transcript' => 'files.applicantTranscript',
            'recommendation' => 'files.applicantRecommendation',
            'cv' => 'files.applicantCv',
            'passport' => 'files.applicantPassport',
        ];

        // What was asked of this applicant is judged at the level they were at when they applied - not the level their
        // profile holds now. An application with no record of that level says nothing about what was asked: it shows
        // the documents it has and no "Not provided" row it cannot stand behind.
        $level = $application->submittedLevel();
        $asked = $level !== null ? array_keys(ApplicantProfile::documentRequirementsAt($level, $application->opportunity)) : [];
        $rows = [];

        // The file the applicant attached to the application itself.
        $rows[] = [
            'label' => $application->document_filename ?: 'Application attachment',
            'state' => $this->stateOf($application->document_path),
            'route' => 'files.applicationDocument',
        ];

        foreach ($routes as $type => $route) {
            $document = $application->documentFor($type);

            // Nothing sent and nothing asked of this applicant: not worth a row.
            if ($document === null && ! in_array($type, $asked, true)) {
                continue;
            }

            $rows[] = [
                'label' => ApplicantProfile::DOCUMENT_LABELS[$type],
                'state' => $document === null ? 'missing' : $this->stateOf($document['path']),
                'route' => $route,
            ];
        }

        return $rows;
    }

    /**
     * What the provider is told about one recorded file: available, unavailable (the file is gone), unreachable
     * (storage could not be asked, so nothing is known about the file) or missing (nothing was recorded).
     */
    private function stateOf(?string $path): string
    {
        if (blank($path)) {
            return 'missing';
        }

        return match ($this->fileStorage->status($path)) {
            FileStorageService::AVAILABLE => 'available',
            FileStorageService::MISSING => 'unavailable',
            default => 'unreachable',
        };
    }

    /**
     * The provider's decision: accept or reject, with a reason the applicant
     * reads verbatim. There is nothing to do afterwards - accepting an
     * application is granting the scholarship. The decision is final: it cannot
     * be withdrawn or changed afterwards.
     */
    public function review(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ApplicationStatus::DECISIONS)],
            'reason' => ['required', 'string', 'max:500'],
            // Optional, and only kept with an acceptance (ApplicationService::decide).
            'message' => ['nullable', 'string', 'max:' . ApplicationService::MAX_ACCEPTANCE_MESSAGE],
        ], [
            'message.max' => 'Keep the message to ' . ApplicationService::MAX_ACCEPTANCE_MESSAGE . ' characters or fewer.',
        ]);

        try {
            $this->applicationService->decide(
                $id,
                $data['status'],
                $data['reason'],
                $request->user(),
                $data['message'] ?? null
            );
        } catch (InvalidApplicationTransition|AwardsFilled $e) {
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
