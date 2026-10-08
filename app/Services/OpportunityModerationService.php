<?php

namespace App\Services;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\RoleNames;
use App\Support\OpportunityLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin review queue for scholarship posts. Approval is the moment a listing
 * becomes public, so it is also the moment applicants get told about it.
 */
class OpportunityModerationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly AuditService $auditService,
        private readonly OpportunityService $opportunityService,
    ) {
    }

    public function pendingQueue()
    {
        return Opportunity::with('provider')
            ->where('moderation_status', OpportunityModerationStatus::PENDING)
            ->orderBy('submitted_at')
            ->orderBy('opportunity_id')
            ->get();
    }

    public function pendingCount(): int
    {
        return Opportunity::where('moderation_status', OpportunityModerationStatus::PENDING)->count();
    }

    /**
     * The queue with a duplicate flag on each row.
     *
     * The check is a prompt to look, never an automatic refusal: two intakes of
     * the same annual bursary are a legitimate pair of rows, and only a person
     * can tell that apart from a double submission.
     */
    public function pendingQueueWithDuplicates()
    {
        return $this->pendingQueue()->map(function (Opportunity $opportunity) {
            $opportunity->setAttribute(
                'duplicate_candidates',
                $this->opportunityService->findPotentialDuplicates($opportunity)
            );

            return $opportunity;
        });
    }

    /**
     * One moderator decision applied to a selection.
     *
     * Every listing still goes through approve()/reject(), so the notification
     * fan-out, the audit entry, and the already-reviewed guard are identical to
     * the single-listing path. Failures are collected rather than thrown: one
     * already-reviewed row must not discard the rest of the batch.
     *
     * @param  array<int, int|string>  $opportunityIds
     * @return array{approved: int, rejected: int, failed: array<int, string>}
     */
    public function bulkReview(array $opportunityIds, string $decision, User $admin, ?string $reason = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $opportunityIds))));

        if ($ids === []) {
            throw ValidationException::withMessages([
                'opportunities' => 'Select at least one scholarship first.',
            ]);
        }

        $rejecting = $decision === 'reject';

        if ($rejecting && blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when declining scholarships - the provider is shown it verbatim.',
            ]);
        }

        $approved = 0;
        $rejected = 0;
        $failed = [];

        foreach ($ids as $id) {
            try {
                if ($rejecting) {
                    $this->reject($id, $admin, (string) $reason);
                    $rejected++;
                } else {
                    $this->approve($id, $admin);
                    $approved++;
                }
            } catch (\Throwable $e) {
                $failed[] = '#' . $id . ': ' . $e->getMessage();
            }
        }

        $this->auditService->log(
            $admin->email,
            AuditAction::BULK_MODERATION,
            'OPPORTUNITY',
            null,
            'Bulk ' . ($rejecting ? 'declined ' . $rejected : 'approved ' . $approved) . ' scholarship(s)'
        );

        return ['approved' => $approved, 'rejected' => $rejected, 'failed' => $failed];
    }

    public function approve(int $opportunityId, User $admin): Opportunity
    {
        $opportunity = $this->requirePending($opportunityId, $admin);

        $opportunity->update([
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'reviewed_at' => Carbon::now(),
            'reviewed_by' => $admin->email,
            'rejection_reason' => null,
            // A person approved it, so whatever it was before, it is not one that went
            // live unreviewed any more.
            'auto_approved' => false,
            'post_reviewed_at' => null,
            'post_reviewed_by' => null,
        ]);

        $this->auditService->log(
            $admin->email,
            AuditAction::APPROVE_OPPORTUNITY,
            'OPPORTUNITY',
            $opportunity->opportunity_id,
            'Published "' . $opportunity->title . '"'
        );

        $this->opportunityService->forgetFacetCaches();

        if ($opportunity->provider) {
            $this->notificationService->notifyUser(
                $opportunity->provider,
                NotificationType::SCHOLARSHIP_APPROVED,
                'Your scholarship "' . $opportunity->title . '" is now live.',
                '/scholarships/' . $opportunity->opportunity_id,
                $opportunity->opportunity_id
            );
        }

        $this->opportunityService->announcePublished($opportunity);

        return $opportunity;
    }

    public function reject(int $opportunityId, User $admin, string $reason): Opportunity
    {
        $opportunity = $this->requirePending($opportunityId, $admin);

        $opportunity->update([
            'moderation_status' => OpportunityModerationStatus::REJECTED,
            'reviewed_at' => Carbon::now(),
            'reviewed_by' => $admin->email,
            'rejection_reason' => $reason,
        ]);

        $this->auditService->log(
            $admin->email,
            AuditAction::REJECT_OPPORTUNITY,
            'OPPORTUNITY',
            $opportunity->opportunity_id,
            'Declined "' . $opportunity->title . '": ' . $reason
        );

        $this->opportunityService->forgetFacetCaches();

        if ($opportunity->provider) {
            $this->notificationService->notifyUser(
                $opportunity->provider,
                NotificationType::SCHOLARSHIP_REJECTED,
                'Your scholarship "' . $opportunity->title . '" needs changes: ' . $reason,
                '/provider/dashboard',
                $opportunity->opportunity_id
            );
        }

        return $opportunity;
    }

    // -------------------------------------------- published without review --

    /**
     * Listings a trusted provider put live that no administrator has looked at yet.
     * Oldest first: the one that has been public longest unchecked is the one
     * that matters most.
     */
    public function autoPublishedQueue()
    {
        return Opportunity::with('provider')
            ->where('auto_approved', true)
            ->whereNull('post_reviewed_at')
            ->where('moderation_status', OpportunityModerationStatus::APPROVED)
            ->orderBy('reviewed_at')
            ->orderBy('opportunity_id')
            ->get();
    }

    public function autoPublishedCount(): int
    {
        return Opportunity::where('auto_approved', true)
            ->whereNull('post_reviewed_at')
            ->where('moderation_status', OpportunityModerationStatus::APPROVED)
            ->count();
    }

    /** "I have looked, it is fine": it leaves the queue and stays live. */
    public function confirmAutoPublished(int $opportunityId, User $admin): Opportunity
    {
        $opportunity = $this->requireAutoPublished($opportunityId, $admin);

        DB::transaction(function () use ($opportunity, $admin) {
            $opportunity->update([
                'post_reviewed_at' => Carbon::now(),
                'post_reviewed_by' => $admin->email,
            ]);

            $this->auditService->logOrFail(
                $admin->email,
                AuditAction::CONFIRM_AUTO_PUBLISHED,
                'OPPORTUNITY',
                $opportunity->opportunity_id,
                'Checked "' . $opportunity->title . '" after it was published without review'
            );
        });

        return $opportunity;
    }

    /**
     * Take a listing that went live unreviewed off the public site, with a reason
     * the provider is shown.
     *
     * It ends REJECTED, the same place a listing declined before publication ends,
     * so the provider's dashboard, their ability to resubmit, and their trust
     * record all read it the way they read any other refusal. The lifecycle has no
     * direct APPROVED -> REJECTED move (an approved listing goes back to review,
     * and review decides), so this takes both legal steps in order inside one
     * transaction rather than widening the table for one caller.
     */
    public function unpublish(int $opportunityId, User $admin, string $reason): Opportunity
    {
        $opportunity = $this->requireAutoPublished($opportunityId, $admin);

        $this->takeDown($opportunity, $admin, $reason, 'Unpublished "' . $opportunity->title . '" after it was published without review: ' . $reason);

        return $opportunity;
    }

    /**
     * Move a live listing to REJECTED via PENDING, audit it, and tell its provider.
     * Shared by unpublish() and by upholding a student report.
     */
    public function takeDown(Opportunity $opportunity, User $admin, string $reason, string $auditDetail): void
    {
        DB::transaction(function () use ($opportunity, $admin, $reason, $auditDetail) {
            if (OpportunityModerationStatus::isApproved($opportunity->moderation_status)) {
                OpportunityLifecycle::assertModeration($opportunity->moderation_status, OpportunityModerationStatus::PENDING);
                $opportunity->moderation_status = OpportunityModerationStatus::PENDING;
            }

            OpportunityLifecycle::assertModeration($opportunity->moderation_status, OpportunityModerationStatus::REJECTED);

            $opportunity->update([
                'moderation_status' => OpportunityModerationStatus::REJECTED,
                'reviewed_at' => Carbon::now(),
                'reviewed_by' => $admin->email,
                'rejection_reason' => $reason,
                'post_reviewed_at' => Carbon::now(),
                'post_reviewed_by' => $admin->email,
            ]);

            $this->auditService->logOrFail(
                $admin->email,
                AuditAction::UNPUBLISH_OPPORTUNITY,
                'OPPORTUNITY',
                $opportunity->opportunity_id,
                $auditDetail,
                ['reason' => $reason]
            );
        });

        $this->opportunityService->forgetFacetCaches();

        if ($opportunity->provider) {
            $this->notificationService->notifyUser(
                $opportunity->provider,
                NotificationType::SCHOLARSHIP_REJECTED,
                'Your scholarship "' . $opportunity->title . '" was taken down: ' . $reason,
                '/provider/dashboard',
                $opportunity->opportunity_id
            );
        }
    }

    private function requireAutoPublished(int $opportunityId, User $admin): Opportunity
    {
        $opportunity = Opportunity::with('provider')->findOrFail($opportunityId);

        if ($opportunity->provider_user_id === $admin->user_id) {
            throw new RuntimeException('You cannot moderate a scholarship you posted yourself.');
        }

        if (! $opportunity->auto_approved
            || $opportunity->post_reviewed_at !== null
            || ! OpportunityModerationStatus::isApproved($opportunity->moderation_status)) {
            throw new RuntimeException('This scholarship is not waiting for a check after being published without review.');
        }

        return $opportunity;
    }

    private function requirePending(int $opportunityId, ?User $admin = null): Opportunity
    {
        $opportunity = Opportunity::with('provider')->findOrFail($opportunityId);

        // Nobody signs off their own listing. Not reachable while a user holds a
        // single role - an administrator cannot also be the provider who posted
        // it - but the rule is the separation of duties the moderation queue
        // exists to enforce, and it should not depend on the shape of the role
        // table staying as it is.
        if ($admin !== null && $opportunity->provider_user_id === $admin->user_id) {
            throw new RuntimeException('You cannot moderate a scholarship you posted yourself.');
        }

        // A draft is its provider's own work. Nobody has been asked to decide on it, and
        // an administrator has no business reading or ruling on it.
        if ($opportunity->isDraft()) {
            throw new RuntimeException('This scholarship is a draft its provider has not submitted, so there is nothing to review.');
        }

        if (! OpportunityModerationStatus::isPending($opportunity->moderation_status)) {
            throw new RuntimeException('This scholarship has already been reviewed.');
        }

        return $opportunity;
    }
}
