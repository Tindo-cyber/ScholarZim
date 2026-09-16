<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\ScoredOpportunity;
use App\Support\ApplicationStatus;
use Illuminate\Support\Carbon;

class ApplicantDashboardService
{
    public function __construct(
        private readonly RecommendationService $recommendationService,
        private readonly SavedScholarshipService $savedScholarshipService,
    ) {
    }

    /**
     * @param  array<int, ScoredOpportunity>  $recommendations  pre-computed via
     *         RecommendationService::forUser(), used to derive the top match
     *         score without re-scoring every listing.
     */
    public function stats(User $user, array $recommendations = []): array
    {
        $applications = Application::where('user_id', $user->user_id);

        return [
            'applications' => (clone $applications)->count(),
            'inProgress' => (clone $applications)
                ->where('application_status', ApplicationStatus::PENDING)
                ->count(),
            'accepted' => (clone $applications)
                ->where('application_status', ApplicationStatus::ACCEPTED)
                ->count(),
            'rejected' => (clone $applications)
                ->where('application_status', ApplicationStatus::REJECTED)
                ->count(),
            'saved' => $this->savedScholarshipService->count($user),
            'profileCompletion' => $user->applicantProfile?->completionPercentage() ?? 0,
            'topMatch' => $recommendations === []
                ? $this->recommendationService->topMatchScore($user)
                : ($recommendations[0]->matchScore ?? 0),
        ];
    }

    public function recentApplications(User $user, int $limit = 5)
    {
        return Application::with('opportunity')
            ->where('user_id', $user->user_id)
            ->orderByDesc('submitted_at')
            ->limit($limit)
            ->get();
    }

    /** Deadlines the applicant has saved or applied to, soonest first. */
    public function upcomingDeadlines(User $user, int $limit = 5)
    {
        $watchedIds = Application::where('user_id', $user->user_id)->pluck('opportunity_id')
            ->merge($this->savedScholarshipService->savedIds($user))
            ->unique();

        if ($watchedIds->isEmpty()) {
            return collect();
        }

        return Opportunity::whereIn('opportunity_id', $watchedIds)
            ->whereNotNull('deadline')
            ->whereDate('deadline', '>=', Carbon::today())
            ->orderBy('deadline')
            ->limit($limit)
            ->get();
    }
}
