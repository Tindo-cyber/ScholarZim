<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\SavedScholarship;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;

/**
 * The provider's own numbers: how many listings they have, how many people saw
 * and saved them, and where their applications stand.
 *
 * Deliberately plain counts. This used to draw a five-stage conversion funnel
 * with per-stage rates, a daily view sparkline, and applicant demographic
 * breakdowns - a BI dashboard for a platform whose objective is only that a
 * provider can publish scholarships and manage the applications to them. What a
 * provider actually acts on is "how many are waiting for me", and that was the
 * one number the funnel buried.
 *
 * Scoped to one provider throughout - a provider must never read another's
 * numbers, so every query starts from their own opportunity ids rather than
 * filtering a platform-wide result at the end.
 */
class ProviderAnalyticsService
{
    public function overview(User $provider): array
    {
        $opportunityIds = Opportunity::where('provider_user_id', $provider->user_id)
            ->pluck('opportunity_id')
            ->all();

        if ($opportunityIds === []) {
            return [
                'listings' => 0,
                'views' => 0,
                'saves' => 0,
                'applications' => 0,
                'pending' => 0,
                'accepted' => 0,
                'rejected' => 0,
                'byListing' => [],
                'moderation' => $this->moderationCounts($provider),
            ];
        }

        $statusCounts = Application::whereIn('opportunity_id', $opportunityIds)
            ->selectRaw('application_status, COUNT(*) as total')
            ->groupBy('application_status')
            ->pluck('total', 'application_status')
            ->all();

        return [
            'listings' => count($opportunityIds),
            'views' => (int) Opportunity::whereIn('opportunity_id', $opportunityIds)->sum('view_count'),
            'saves' => SavedScholarship::whereIn('opportunity_id', $opportunityIds)->count(),
            'applications' => array_sum($statusCounts),
            'pending' => (int) ($statusCounts[ApplicationStatus::PENDING] ?? 0),
            'accepted' => (int) ($statusCounts[ApplicationStatus::ACCEPTED] ?? 0),
            'rejected' => (int) ($statusCounts[ApplicationStatus::REJECTED] ?? 0),
            'byListing' => $this->byListing($opportunityIds),
            'moderation' => $this->moderationCounts($provider),
        ];
    }

    /**
     * One row per listing, so a provider can see which post is doing the work.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byListing(array $opportunityIds): array
    {
        $opportunities = Opportunity::whereIn('opportunity_id', $opportunityIds)
            ->withCount(['applications', 'savedBy'])
            ->orderByDesc('view_count')
            ->limit(20)
            ->get();

        $accepted = Application::whereIn('opportunity_id', $opportunityIds)
            ->where('application_status', ApplicationStatus::ACCEPTED)
            ->selectRaw('opportunity_id, COUNT(*) as total')
            ->groupBy('opportunity_id')
            ->pluck('total', 'opportunity_id')
            ->all();

        return $opportunities->map(static fn (Opportunity $o) => [
            'opportunity' => $o,
            'views' => (int) $o->view_count,
            'saves' => (int) $o->saved_by_count,
            'applications' => (int) $o->applications_count,
            'accepted' => (int) ($accepted[$o->opportunity_id] ?? 0),
        ])->all();
    }

    /** Where this provider's listings stand with the moderators. */
    private function moderationCounts(User $provider): array
    {
        $counts = Opportunity::where('provider_user_id', $provider->user_id)
            ->selectRaw('moderation_status, COUNT(*) as total')
            ->groupBy('moderation_status')
            ->pluck('total', 'moderation_status')
            ->all();

        return [
            'approved' => (int) ($counts[OpportunityModerationStatus::APPROVED] ?? 0),
            'pending' => (int) ($counts[OpportunityModerationStatus::PENDING] ?? 0),
            'rejected' => (int) ($counts[OpportunityModerationStatus::REJECTED] ?? 0),
        ];
    }
}
