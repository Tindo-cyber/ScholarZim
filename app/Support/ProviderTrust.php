<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * How far a provider's record has earned them being published without a review.
 *
 * Reviewing every listing for ever does not scale and punishes the providers who
 * have given no reason to be doubted; trusting every provider from day one
 * hands the platform to the first person who posts a fake bursary. This sits
 * between: a provider starts with every listing reviewed, and earns the right to
 * go live first by a record anyone can read here - enough approved listings, no
 * upheld complaint, no recent refusal. The thresholds are in config/
 * scholarzim.php, not in this file, so they can be tuned without a deploy of
 * code.
 *
 * An administrator can override the computed answer either way
 * (provider_profiles.trusted_override). That is the escape hatch for the cases a
 * rule cannot see: a known institution on day one, a provider whose record looks
 * clean but who an administrator has reason to doubt.
 *
 * Trust only decides whether a NEW listing waits for review. It never overrides
 * the risk checker: a flagged listing is reviewed first whoever posted it.
 */
final class ProviderTrust
{
    private function __construct()
    {
    }

    public static function isTrusted(User $provider): bool
    {
        return self::assess($provider)['trusted'];
    }

    /**
     * The verdict and everything it rests on, so an administrator can see why a
     * provider is or is not trusted rather than being handed a bare yes or no.
     *
     * @return array{
     *     trusted: bool,
     *     source: string,
     *     approved: int,
     *     required: int,
     *     upheld_reports: int,
     *     recent_rejections: int,
     *     window_days: int,
     *     reasons: array<int, string>
     * }
     */
    public static function assess(User $provider): array
    {
        $required = max(1, (int) config('scholarzim.trust.approved_listings_required', 3));
        $window = max(1, (int) config('scholarzim.trust.rejection_window_days', 90));

        $approved = Opportunity::query()
            ->where('provider_user_id', $provider->user_id)
            ->where('moderation_status', OpportunityModerationStatus::APPROVED)
            ->count();

        $upheld = OpportunityReport::query()
            ->where('status', ReportStatus::UPHELD)
            ->whereIn('opportunity_id', self::listingIds($provider))
            ->count();

        $rejections = AuditLog::query()
            // A listing taken down after it went live is as much a refusal as one
            // declined before.
            ->whereIn('action', [AuditAction::REJECT_OPPORTUNITY, AuditAction::UNPUBLISH_OPPORTUNITY])
            ->where('entity_type', 'OPPORTUNITY')
            ->whereIn('entity_id', self::listingIds($provider))
            ->where('created_at', '>=', Carbon::now()->subDays($window))
            ->count();

        $reasons = [];

        if ($approved < $required) {
            $reasons[] = 'Has ' . $approved . ' approved ' . ($approved === 1 ? 'listing' : 'listings') . '; needs ' . $required . '.';
        }

        if ($upheld > 0) {
            $reasons[] = $upheld . ' student ' . ($upheld === 1 ? 'report' : 'reports') . ' against them ' . ($upheld === 1 ? 'was' : 'were') . ' upheld.';
        }

        if ($rejections > 0) {
            $reasons[] = $rejections . ' ' . ($rejections === 1 ? 'listing was' : 'listings were') . ' declined in the last ' . $window . ' days.';
        }

        $override = $provider->providerProfile?->trusted_override;

        if ($override !== null) {
            return [
                'trusted' => (bool) $override,
                'source' => 'override',
                'approved' => $approved,
                'required' => $required,
                'upheld_reports' => $upheld,
                'recent_rejections' => $rejections,
                'window_days' => $window,
                'reasons' => [$override ? 'Trusted by an administrator.' : 'Trust withheld by an administrator.'],
            ];
        }

        return [
            'trusted' => $reasons === [],
            'source' => 'record',
            'approved' => $approved,
            'required' => $required,
            'upheld_reports' => $upheld,
            'recent_rejections' => $rejections,
            'window_days' => $window,
            'reasons' => $reasons === [] ? ['Meets the record required for trust.'] : $reasons,
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Opportunity> a subquery of the provider's listing ids */
    private static function listingIds(User $provider)
    {
        return Opportunity::query()->select('opportunity_id')->where('provider_user_id', $provider->user_id);
    }
}
