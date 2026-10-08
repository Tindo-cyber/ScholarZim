<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Illuminate\Support\Carbon;

class ProviderService
{
    public function dashboardStats(User $provider): array
    {
        // Drafts are the provider's own, but they are not listings yet and are not counted.
        $opportunityIds = Opportunity::where('provider_user_id', $provider->user_id)
            ->notDraft()
            ->pluck('opportunity_id');

        $applications = Application::whereIn('opportunity_id', $opportunityIds);

        return [
            'totalOpportunities' => $opportunityIds->count(),
            // Live is what the public can actually see - the rule
            // OpportunityLifecycle::isPubliclyVisible() states, applied by the same scope.
            // Counting every APPROVED listing also counted the withdrawn, the closed and
            // the ones whose deadline had passed, so a provider was told they had more
            // scholarships on the site than applicants could find.
            'liveOpportunities' => Opportunity::where('provider_user_id', $provider->user_id)
                ->publiclyVisible()
                ->count(),
            'awaitingReview' => Opportunity::where('provider_user_id', $provider->user_id)
                ->where('moderation_status', OpportunityModerationStatus::PENDING)
                ->count(),
            'applicationsReceived' => (clone $applications)->count(),
            // The same rule pendingApplications() lists by, so the number and the
            // table beside it cannot disagree about a legacy or unset status.
            'pendingApplications' => (clone $applications)
                ->awaitingDecision()
                ->count(),
            'acceptedApplications' => (clone $applications)
                ->where('application_status', ApplicationStatus::ACCEPTED)
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

    /**
     * Applications waiting on this provider's decision, longest-waiting first.
     *
     * Queried directly. The dashboard used to filter recentApplications(8) down
     * to the pending ones, so when the eight newest were all decided it said
     * "Nothing waiting on you" while older applications sat undecided - the one
     * thing that page exists to prevent. Oldest first because those applicants
     * have waited longest.
     */
    public function pendingApplications(User $provider, int $limit = 8)
    {
        return Application::with(['opportunity', 'user'])
            ->whereHas('opportunity', fn ($q) => $q->where('provider_user_id', $provider->user_id))
            ->awaitingDecision()
            ->orderBy('submitted_at')
            ->orderBy('application_id')
            ->limit($limit)
            ->get();
    }

    /**
     * Closing dates the provider should know about.
     *
     * Withdrawn listings are left out - nobody is waiting on their deadline.
     * Listings that are not live yet (awaiting review, or declined) stay in, so a
     * provider sees a date they are about to miss, but the dashboard labels them:
     * a bare date implies applicants can already see the listing.
     */
    public function upcomingDeadlines(User $provider, int $limit = 5)
    {
        return Opportunity::where('provider_user_id', $provider->user_id)
            ->notDraft()
            ->whereNotNull('deadline')
            ->whereDate('deadline', '>=', Carbon::today())
            ->where('status', '!=', OpportunityStatus::WITHDRAWN)
            ->orderBy('deadline')
            ->orderBy('opportunity_id')
            ->limit($limit)
            ->get();
    }
}
