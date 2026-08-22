<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;
use Illuminate\Support\Carbon;

class ProviderService
{
    public function dashboardStats(User $provider): array
    {
        $opportunityIds = Opportunity::where('provider_user_id', $provider->user_id)
            ->pluck('opportunity_id');

        $applications = Application::whereIn('opportunity_id', $opportunityIds);

        return [
            'totalOpportunities' => $opportunityIds->count(),
            'liveOpportunities' => Opportunity::where('provider_user_id', $provider->user_id)
                ->where('moderation_status', OpportunityModerationStatus::APPROVED)
                ->count(),
            'awaitingReview' => Opportunity::where('provider_user_id', $provider->user_id)
                ->where('moderation_status', OpportunityModerationStatus::PENDING)
                ->count(),
            'applicationsReceived' => (clone $applications)->count(),
            'pendingApplications' => (clone $applications)->whereIn('application_status', [
                ApplicationStatus::SUBMITTED,
                ApplicationStatus::UNDER_REVIEW,
            ])->count(),
            'approvedApplications' => (clone $applications)
                ->where('application_status', ApplicationStatus::APPROVED)
                ->count(),
        ];
    }

    public function myOpportunities(User $provider)
    {
        return Opportunity::where('provider_user_id', $provider->user_id)
            ->withCount('applications')
            ->orderByDesc('created_at')
            ->get();
    }

    public function recentApplications(User $provider, int $limit = 8)
    {
        return Application::with(['opportunity', 'user'])
            ->whereHas('opportunity', fn ($q) => $q->where('provider_user_id', $provider->user_id))
            ->orderByDesc('submitted_at')
            ->limit($limit)
            ->get();
    }

    public function upcomingDeadlines(User $provider, int $limit = 5)
    {
        return Opportunity::where('provider_user_id', $provider->user_id)
            ->whereNotNull('deadline')
            ->whereDate('deadline', '>=', Carbon::today())
            ->orderBy('deadline')
            ->limit($limit)
            ->get();
    }
}
