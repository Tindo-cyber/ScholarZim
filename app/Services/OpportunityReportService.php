<?php

namespace App\Services;

use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\OpportunityLifecycle;
use App\Support\OpportunityModerationStatus;
use App\Support\ReportReason;
use App\Support\ReportStatus;
use App\Support\RoleNames;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Students reporting listings, and what an administrator does about it.
 *
 * Moderation before publication catches what a reviewer can see in the text. A
 * student who has been asked for money, or cannot find the organisation, knows
 * something a reviewer cannot, and this is how it gets back to the platform.
 *
 * Reports are weighed by how many DIFFERENT students make them, not how many
 * times: one person pressing the button repeatedly is one opinion, and is stopped
 * by the unique key as well as by the rate limit. Once `hide_after` of them are
 * waiting, the listing comes off the public site and goes back to the review
 * queue - through the ordinary APPROVED -> PENDING move, so it is visible to
 * administrators exactly where they already look - until somebody decides.
 */
class OpportunityReportService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
        private readonly OpportunityService $opportunityService,
        private readonly OpportunityModerationService $moderationService,
        private readonly ListingRiskChecker $riskChecker,
    ) {
    }

    public function hasReported(?User $user, int $opportunityId): bool
    {
        return $user !== null
            && OpportunityReport::where('opportunity_id', $opportunityId)->where('user_id', $user->user_id)->exists();
    }

    /**
     * File a report. The listing must be on the public site: a student cannot
     * have seen one that is not, and "report" is not a way to probe for them.
     */
    public function submit(User $reporter, int $opportunityId, string $reason, ?string $details): OpportunityReport
    {
        $opportunity = Opportunity::query()->publiclyVisible()->find($opportunityId);

        abort_if($opportunity === null, 404, 'Scholarship not found.');

        if ($this->hasReported($reporter, $opportunityId)) {
            throw new RuntimeException('You have already reported this listing. An administrator will look at it.');
        }

        $report = DB::transaction(function () use ($reporter, $opportunity, $reason, $details) {
            $report = OpportunityReport::create([
                'opportunity_id' => $opportunity->opportunity_id,
                'user_id' => $reporter->user_id,
                'reason' => $reason,
                'details' => filled($details) ? trim($details) : null,
                'status' => ReportStatus::PENDING,
                'created_at' => Carbon::now(),
            ]);

            $this->auditService->logOrFail(
                $reporter->email,
                AuditAction::REPORT_OPPORTUNITY,
                'OPPORTUNITY',
                $opportunity->opportunity_id,
                'Reported "' . $opportunity->title . '": ' . ReportReason::label($reason)
            );

            return $report;
        });

        $this->hideIfReportedEnough($opportunity);

        return $report;
    }

    /** Listings with at least one pending report, most reported first. */
    public function queue()
    {
        return Opportunity::with(['provider', 'reports' => fn ($q) => $q->where('status', ReportStatus::PENDING)->with('user')->orderBy('created_at')])
            ->whereHas('reports', fn ($q) => $q->where('status', ReportStatus::PENDING))
            ->notDraft()
            ->withCount(['reports as pending_reports_count' => fn ($q) => $q->where('status', ReportStatus::PENDING)])
            ->orderByDesc('pending_reports_count')
            ->orderBy('opportunity_id')
            ->get();
    }

    public function pendingListingCount(): int
    {
        return Opportunity::whereHas('reports', fn ($q) => $q->where('status', ReportStatus::PENDING))->notDraft()->count();
    }

    /** The reports are wrong, or not enough: close them, and put the listing back if reports had hidden it. */
    public function dismiss(int $opportunityId, User $admin): Opportunity
    {
        $opportunity = $this->requirePendingReports($opportunityId);

        DB::transaction(function () use ($opportunity, $admin) {
            $count = $this->resolveReports($opportunity, ReportStatus::DISMISSED, $admin);

            if ($this->isHiddenByReports($opportunity)) {
                OpportunityLifecycle::assertModeration($opportunity->moderation_status, OpportunityModerationStatus::APPROVED);

                $opportunity->update([
                    'moderation_status' => OpportunityModerationStatus::APPROVED,
                    'reviewed_at' => Carbon::now(),
                    'reviewed_by' => $admin->email,
                    'risk_flags' => $this->withoutReportedFlag($opportunity) ?: null,
                ]);
            }

            $this->auditService->logOrFail(
                $admin->email,
                AuditAction::RESOLVE_REPORTS,
                'OPPORTUNITY',
                $opportunity->opportunity_id,
                'Dismissed ' . $count . ' report(s) on "' . $opportunity->title . '"'
            );
        });

        $this->opportunityService->forgetFacetCaches();

        return $opportunity->fresh();
    }

    /**
     * The reports are right. The listing comes down, and the provider loses
     * trust: ProviderTrust already refuses anyone with an upheld report, and an
     * administrator's earlier grant is cleared so it cannot outvote this.
     */
    public function uphold(int $opportunityId, User $admin, string $reason): Opportunity
    {
        $opportunity = $this->requirePendingReports($opportunityId);

        DB::transaction(function () use ($opportunity, $admin, $reason) {
            $count = $this->resolveReports($opportunity, ReportStatus::UPHELD, $admin);

            $profile = $opportunity->provider?->providerProfile;

            if ($profile !== null && $profile->trusted_override === true) {
                $profile->update(['trusted_override' => null]);
            }

            $this->auditService->logOrFail(
                $admin->email,
                AuditAction::RESOLVE_REPORTS,
                'OPPORTUNITY',
                $opportunity->opportunity_id,
                'Upheld ' . $count . ' report(s) on "' . $opportunity->title . '": ' . $reason,
                ['reason' => $reason]
            );
        });

        // Only a listing still in play is taken down; one already refused or
        // withdrawn has nothing left to take down.
        if (OpportunityModerationStatus::isApproved($opportunity->moderation_status)
            || OpportunityModerationStatus::isPending($opportunity->moderation_status)) {
            $this->moderationService->takeDown(
                $opportunity,
                $admin,
                $reason,
                'Took down "' . $opportunity->title . '" after upheld student reports: ' . $reason
            );
        }

        return $opportunity->fresh();
    }

    // ----------------------------------------------------------- internals --

    private function hideIfReportedEnough(Opportunity $opportunity): void
    {
        $threshold = max(1, (int) config('scholarzim.reports.hide_after', 3));

        $reporters = OpportunityReport::where('opportunity_id', $opportunity->opportunity_id)
            ->where('status', ReportStatus::PENDING)
            ->distinct()
            ->count('user_id');

        $opportunity->refresh();

        if ($reporters < $threshold || ! OpportunityModerationStatus::isApproved($opportunity->moderation_status)) {
            return;
        }

        DB::transaction(function () use ($opportunity, $reporters) {
            OpportunityLifecycle::assertModeration($opportunity->moderation_status, OpportunityModerationStatus::PENDING);

            $opportunity->update([
                'moderation_status' => OpportunityModerationStatus::PENDING,
                'submitted_at' => Carbon::now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
                'auto_approved' => false,
                'post_reviewed_at' => null,
                'post_reviewed_by' => null,
                'risk_flags' => array_merge($this->withoutReportedFlag($opportunity), [[
                    'code' => ListingRiskChecker::REPORTED,
                    'message' => 'Reported by ' . $reporters . ' different students and taken off the public site until an administrator decides.',
                ]]),
            ]);

            $this->auditService->logOrFail(
                OpportunityService::AUTO_APPROVER,
                AuditAction::HIDE_REPORTED_OPPORTUNITY,
                'OPPORTUNITY',
                $opportunity->opportunity_id,
                'Hid "' . $opportunity->title . '" after reports from ' . $reporters . ' students'
            );
        });

        $this->opportunityService->forgetFacetCaches();

        $admins = User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->get();

        $this->notificationService->notifyMany(
            $admins,
            NotificationType::SCHOLARSHIP_REPORTED,
            '"' . $opportunity->title . '" was reported by ' . $reporters . ' students and hidden from the public site. Decide whether the reports are right.',
            '/admin/listing-reports',
            $opportunity->opportunity_id
        );

        if ($opportunity->provider) {
            $this->notificationService->notifyUser(
                $opportunity->provider,
                NotificationType::SCHOLARSHIP_UPDATED,
                'Your scholarship "' . $opportunity->title . '" was taken off the public site while an administrator looks into reports from students. You will be told what they decide.',
                '/provider/dashboard',
                $opportunity->opportunity_id
            );
        }
    }

    /** True when the listing is waiting in the queue because of reports, rather than for an ordinary review. */
    private function isHiddenByReports(Opportunity $opportunity): bool
    {
        return OpportunityModerationStatus::isPending($opportunity->moderation_status)
            && in_array(ListingRiskChecker::REPORTED, array_column((array) $opportunity->risk_flags, 'code'), true);
    }

    /** @return array<int, array{code: string, message: string}> */
    private function withoutReportedFlag(Opportunity $opportunity): array
    {
        return array_values(array_filter(
            (array) $opportunity->risk_flags,
            fn ($flag) => ($flag['code'] ?? null) !== ListingRiskChecker::REPORTED
        ));
    }

    private function resolveReports(Opportunity $opportunity, string $status, User $admin): int
    {
        return OpportunityReport::where('opportunity_id', $opportunity->opportunity_id)
            ->where('status', ReportStatus::PENDING)
            ->update([
                'status' => $status,
                'reviewed_by' => $admin->email,
                'reviewed_at' => Carbon::now(),
            ]);
    }

    private function requirePendingReports(int $opportunityId): Opportunity
    {
        $opportunity = Opportunity::with('provider.providerProfile')->findOrFail($opportunityId);

        if (! OpportunityReport::where('opportunity_id', $opportunityId)->where('status', ReportStatus::PENDING)->exists()) {
            throw new RuntimeException('There are no pending reports on this scholarship.');
        }

        return $opportunity;
    }
}
