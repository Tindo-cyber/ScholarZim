<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OpportunityView;
use App\Models\SavedScholarship;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;
use Illuminate\Support\Carbon;

/**
 * The provider's own numbers: how many people saw a listing, how many saved it,
 * how many applied, and how many were awarded.
 *
 * Scoped to one provider throughout - a provider must never be able to read
 * another's funnel, so every query starts from their own opportunity ids rather
 * than filtering a platform-wide result at the end.
 */
class ProviderAnalyticsService
{
    /** Funnel stages, in the order they are drawn. */
    public const STAGES = ['Views', 'Saves', 'Applications', 'Awarded'];

    public function overview(User $provider, int $days = 30): array
    {
        $opportunityIds = Opportunity::where('provider_user_id', $provider->user_id)
            ->pluck('opportunity_id')
            ->all();

        if ($opportunityIds === []) {
            return $this->emptyOverview($days);
        }

        $views = (int) Opportunity::whereIn('opportunity_id', $opportunityIds)->sum('view_count');
        $saves = SavedScholarship::whereIn('opportunity_id', $opportunityIds)->count();

        $statusCounts = Application::whereIn('opportunity_id', $opportunityIds)
            ->selectRaw('application_status, COUNT(*) as total')
            ->groupBy('application_status')
            ->pluck('total', 'application_status')
            ->all();

        $applications = array_sum($statusCounts);
        $awarded = (int) ($statusCounts[ApplicationStatus::APPROVED] ?? 0);

        return [
            'days' => $days,
            'funnel' => $this->funnel($views, $saves, $applications, $awarded),
            'statusCounts' => $statusCounts,
            'viewTrend' => $this->viewTrend($opportunityIds, $days),
            'byListing' => $this->byListing($opportunityIds),
            'fieldBreakdown' => $this->applicantBreakdown($opportunityIds, 'field_of_study'),
            'levelBreakdown' => $this->applicantBreakdown($opportunityIds, 'education_level'),
            'moderation' => $this->moderationCounts($provider),
            'conversionRate' => $applications > 0 && $views > 0
                ? round($applications / $views * 100, 1)
                : 0.0,
            'awardRate' => $applications > 0 ? round($awarded / $applications * 100, 1) : 0.0,
        ];
    }

    /**
     * Each stage carries its share of the stage above it, which is the number a
     * provider actually acts on - "300 views, 12 applications" only means
     * something once it reads as 4%.
     */
    private function funnel(int $views, int $saves, int $applications, int $awarded): array
    {
        $stages = [
            ['label' => 'Views', 'value' => $views],
            ['label' => 'Saves', 'value' => $saves],
            ['label' => 'Applications', 'value' => $applications],
            ['label' => 'Awarded', 'value' => $awarded],
        ];

        $top = max($views, 1);
        $previous = null;

        return array_map(static function (array $stage) use ($top, &$previous) {
            $stage['share'] = (int) round($stage['value'] / $top * 100);
            $stage['stepRate'] = $previous === null || $previous === 0
                ? null
                : round($stage['value'] / $previous * 100, 1);
            $previous = $stage['value'];

            return $stage;
        }, $stages);
    }

    /** Daily view counts for the sparkline, zero-filled so the axis is continuous. */
    private function viewTrend(array $opportunityIds, int $days): array
    {
        $start = Carbon::today()->subDays($days - 1);

        $rows = OpportunityView::whereIn('opportunity_id', $opportunityIds)
            ->where('viewed_on', '>=', $start->toDateString())
            ->selectRaw('viewed_on, SUM(views) as total')
            ->groupBy('viewed_on')
            ->pluck('total', 'viewed_on')
            ->all();

        // Keys come back as dates or date-times depending on the driver, so they
        // are normalised before lookup rather than trusted.
        $normalised = [];
        foreach ($rows as $day => $total) {
            $normalised[Carbon::parse($day)->toDateString()] = (int) $total;
        }

        $trend = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $trend[] = ['date' => $day, 'views' => $normalised[$day] ?? 0];
        }

        return $trend;
    }

    /** Per-listing funnel, so a provider can see which post is doing the work. */
    private function byListing(array $opportunityIds): array
    {
        $opportunities = Opportunity::whereIn('opportunity_id', $opportunityIds)
            ->withCount(['applications', 'savedBy'])
            ->orderByDesc('view_count')
            ->limit(20)
            ->get();

        $awarded = Application::whereIn('opportunity_id', $opportunityIds)
            ->where('application_status', ApplicationStatus::APPROVED)
            ->selectRaw('opportunity_id, COUNT(*) as total')
            ->groupBy('opportunity_id')
            ->pluck('total', 'opportunity_id')
            ->all();

        return $opportunities->map(static fn (Opportunity $o) => [
            'opportunity' => $o,
            'views' => (int) $o->view_count,
            'saves' => (int) $o->saved_by_count,
            'applications' => (int) $o->applications_count,
            'awarded' => (int) ($awarded[$o->opportunity_id] ?? 0),
        ])->all();
    }

    /**
     * Who is applying, grouped by a profile column. Applicants with the column
     * blank are counted under "Not stated" rather than dropped, so the totals
     * still reconcile with the application count.
     */
    private function applicantBreakdown(array $opportunityIds, string $column): array
    {
        $rows = Application::whereIn('applications.opportunity_id', $opportunityIds)
            ->leftJoin('applicant_profiles', 'applicant_profiles.user_id', '=', 'applications.user_id')
            ->selectRaw('applicant_profiles.' . $column . ' as bucket, COUNT(*) as total')
            ->groupBy('bucket')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return $rows->map(static fn ($row) => [
            'label' => filled($row->bucket) ? $row->bucket : 'Not stated',
            'total' => (int) $row->total,
        ])->all();
    }

    private function moderationCounts(User $provider): array
    {
        return Opportunity::where('provider_user_id', $provider->user_id)
            ->selectRaw('moderation_status, COUNT(*) as total')
            ->groupBy('moderation_status')
            ->pluck('total', 'moderation_status')
            ->all()
            + [
                OpportunityModerationStatus::APPROVED => 0,
                OpportunityModerationStatus::PENDING => 0,
            ];
    }

    private function emptyOverview(int $days): array
    {
        return [
            'days' => $days,
            'funnel' => $this->funnel(0, 0, 0, 0),
            'statusCounts' => [],
            'viewTrend' => $this->viewTrend([0], $days),
            'byListing' => [],
            'fieldBreakdown' => [],
            'levelBreakdown' => [],
            'moderation' => [],
            'conversionRate' => 0.0,
            'awardRate' => 0.0,
        ];
    }
}
