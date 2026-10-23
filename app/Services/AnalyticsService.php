<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Chart data for the admin analytics page. Every series is returned as
 * ['labels' => [...], 'data' => [...]] so the Blade layer can hand it straight
 * to ApexCharts without reshaping.
 */
class AnalyticsService
{
    /** Applications submitted per month, over a trailing window. */
    public function applicationsPerMonth(int $months = 12): array
    {
        return $this->monthlySeries(
            Application::query(),
            'submitted_at',
            $months
        );
    }

    /** Listings created per month, over the same window. */
    public function opportunitiesPerMonth(int $months = 12): array
    {
        return $this->monthlySeries(
            Opportunity::query()->notDraft(),
            'created_at',
            $months
        );
    }

    public function signupsPerMonth(int $months = 12): array
    {
        return $this->monthlySeries(User::query(), 'created_at', $months);
    }

    /** Application outcomes, for the status donut. */
    public function applicationStatusMix(): array
    {
        $counts = Application::selectRaw('application_status, COUNT(*) as total')
            ->groupBy('application_status')
            ->pluck('total', 'application_status')
            ->all();

        $labels = [];
        $data = [];

        foreach ($counts as $status => $total) {
            $labels[] = ApplicationStatus::displayLabel($status);
            $data[] = (int) $total;
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** Which fields of study attract the most listings: the fields each is open to, or an older listing's own label. */
    public function topFields(int $limit = 8): array
    {
        $counts = [];

        Opportunity::query()
            ->publiclyVisible()
            ->with(['scopes.field', 'scopes.programme.field'])
            ->get()
            ->each(function (Opportunity $opportunity) use (&$counts) {
                foreach ($opportunity->subjectFields() as $label) {
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
            });

        arsort($counts);
        $counts = array_slice($counts, 0, $limit, true);

        return [
            'labels' => array_keys($counts),
            'data' => array_values($counts),
        ];
    }

    /** Providers ranked by how many applications their listings attracted. */
    public function topProviders(int $limit = 8)
    {
        // Grouped by provider only: grouping by listing as well would rank
        // individual listings rather than the organisations behind them.
        return Opportunity::query()
            ->selectRaw('provider_name')
            ->selectRaw('COUNT(*) as listings')
            ->selectRaw('(SELECT COUNT(*) FROM applications a'
                . ' WHERE a.opportunity_id IN'
                . ' (SELECT o2.opportunity_id FROM opportunities o2'
                . '  WHERE o2.provider_name = opportunities.provider_name'
                . "  AND o2.moderation_status <> 'DRAFT')) as applications")
            ->notDraft()
            ->whereNotNull('provider_name')
            ->where('provider_name', '<>', '')
            ->groupBy('provider_name')
            ->orderByDesc('applications')
            ->limit($limit)
            ->get();
    }

    private function monthlySeries($query, string $column, int $months): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);

        $rows = $query
            ->whereNotNull($column)
            ->where($column, '>=', $start)
            ->selectRaw($this->monthBucketExpression($column) . ' as bucket, COUNT(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->all();

        $labels = [];
        $data = [];

        // Walk every month in the window so gaps render as zero rather than
        // collapsing the axis.
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $labels[] = $month->format('M Y');
            $data[] = (int) ($rows[$key] ?? 0);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * Year-month bucket, per driver. MySQL is the production target; the SQLite
     * branch keeps the page working for local runs and the test suite.
     */
    private function monthBucketExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
