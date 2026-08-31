<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Services\ApplicantProfileService;
use App\Services\ApplicationService;
use App\Services\RecommendationService;
use App\Services\SavedScholarshipService;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function __construct(
        private readonly RecommendationService $recommendationService,
        private readonly ApplicantProfileService $profileService,
        private readonly SavedScholarshipService $savedScholarshipService,
        private readonly ApplicationService $applicationService,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $profile = $this->profileService->forUser($user);

        $minimumScore = (int) $request->query('min_score', 0);

        return view('applicant.recommendations', [
            'profile' => $profile,
            'matches' => $this->recommendationService->forUser($user, 24, $minimumScore),
            'minimumScore' => $minimumScore,
            'savedIds' => $this->savedScholarshipService->savedIds($user),
            'appliedIds' => $this->applicationService->appliedIds($user),
            'accepted' => $this->applicationService->acceptedByOpportunity($user),
        ]);
    }
}
