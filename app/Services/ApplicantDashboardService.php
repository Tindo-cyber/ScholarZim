<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use Illuminate\Support\Carbon;

class ApplicantDashboardService
{
    public function __construct(
        private readonly RecommendationService $recommendationService,
        private readonly SavedScholarshipService $savedScholarshipService,
    ) {
    }

    public function stats(User $user): array
    {
        $applications = Application::where('user_id', $user->user_id);

        return [
            'applications' => (clone $applications)->count(),
            'inProgress' => (clone $applications)->whereIn('application_status', [
                ApplicationStatus::SUBMITTED,
                ApplicationStatus::UNDER_REVIEW,
                ApplicationStatus::DOCUMENTS_REQUESTED,
                ApplicationStatus::SHORTLISTED,
                ApplicationStatus::INTERVIEW,
                ApplicationStatus::WAITLISTED,
            ])->count(),
            'approved' => (clone $applications)
                ->where('application_status', ApplicationStatus::APPROVED)
                ->count(),
            'saved' => $this->savedScholarshipService->count($user),
            'profileCompletion' => $user->applicantProfile?->completionPercentage() ?? 0,
            'topMatch' => $this->recommendationService->topMatchScore($user),
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
