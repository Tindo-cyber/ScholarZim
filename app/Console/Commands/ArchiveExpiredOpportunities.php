<?php

namespace App\Console\Commands;

use App\Models\Opportunity;
use App\Services\NotificationService;
use App\Support\NotificationType;
use App\Support\OpportunityLifecycle;
use App\Support\OpportunityStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily sweep that archives scholarships once their deadline passes. Closing
 * the status explicitly (rather than relying only on the deadline check baked
 * into Opportunity::scopePubliclyVisible()) is what keeps a stale listing from
 * being reachable by direct link and lets dashboards show it as archived.
 */
class ArchiveExpiredOpportunities extends Command
{
    protected $signature = 'scholarzim:archive-expired-opportunities';

    protected $description = 'Close scholarships whose application deadline has passed';

    public function handle(NotificationService $notifications): int
    {
        $expired = Opportunity::where('status', OpportunityStatus::ACTIVE)
            ->whereNotNull('deadline')
            ->where('deadline', '<', Carbon::today()->toDateString())
            ->get();

        foreach ($expired as $opportunity) {
            // Guarded rather than assumed: the query selects ACTIVE rows, and
            // this keeps that true if the query is ever widened. A withdrawn
            // listing must not be quietly re-labelled as merely closed.
            if (! OpportunityLifecycle::canTransitionPublication($opportunity->status, OpportunityStatus::CLOSED)) {
                continue;
            }

            $opportunity->update(['status' => OpportunityStatus::CLOSED]);

            if ($opportunity->provider) {
                $notifications->notifyUser(
                    $opportunity->provider,
                    NotificationType::SCHOLARSHIP_CLOSED,
                    'Your scholarship "' . $opportunity->title . '" has been archived because its deadline passed.',
                    '/provider/dashboard',
                    $opportunity->opportunity_id
                );
            }
        }

        $this->info("Archived {$expired->count()} expired opportunity(ies)");

        return self::SUCCESS;
    }
}
