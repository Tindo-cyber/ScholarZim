<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Services\ApplicantDashboardService;
use App\Services\ApplicantProfileService;
use App\Services\ApplicationService;
use App\Services\RecommendationService;
use App\Services\SavedScholarshipService;
use App\Support\Greeting;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ApplicantDashboardService $dashboardService,
        private readonly RecommendationService $recommendationService,
        private readonly ApplicantProfileService $profileService,
        private readonly ApplicationService $applicationService,
        private readonly SavedScholarshipService $savedScholarshipService,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $profile = $this->profileService->forUser($user);

        return view('applicant.dashboard', [
            'greeting' => Greeting::forUser($user->full_name),
            'profile' => $profile,
            'stats' => $this->dashboardService->stats($user),
            'recentApplications' => $this->dashboardService->recentApplications($user),
            'upcomingDeadlines' => $this->dashboardService->upcomingDeadlines($user),
            'recommendations' => $this->recommendationService->forUser($user, 4),
            // The match cards carry the same save button as the browse page, so
            // they need the same saved list behind it - without it every card
            // renders as unsaved and its button posts to the store route, so a
            // student cannot unsave from here and re-saving is the only outcome.
            'savedIds' => $this->savedScholarshipService->savedIds($user),
            'appliedIds' => $this->applicationService->appliedIds($user),
            'awards' => $this->applicationService->awardsByOpportunity($user),
        ]);
    }
}
