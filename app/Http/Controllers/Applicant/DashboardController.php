<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Services\ApplicantDashboardService;
use App\Services\ApplicantProfileService;
use App\Services\RecommendationService;
use App\Support\Greeting;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ApplicantDashboardService $dashboardService,
        private readonly RecommendationService $recommendationService,
        private readonly ApplicantProfileService $profileService,
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
        ]);
    }
}
