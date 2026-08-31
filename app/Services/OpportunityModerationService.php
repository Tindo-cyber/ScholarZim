<?php

namespace App\Services;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\RoleNames;
use Illuminate\Support\Carbon;
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

        $this->announceToApplicants($opportunity);

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

    /**
     * Only applicants hear about a newly published listing, and only once it is
     * actually visible on the public site.
     */
    private function announceToApplicants(Opportunity $opportunity): void
    {
        $applicants = User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::APPLICANT))
            ->where('email_notify_scholarships', true)
            ->get();

        $this->notificationService->notifyMany(
            $applicants,
            NotificationType::NEW_OPPORTUNITY,
            'New scholarship published: "' . $opportunity->title . '".',
            '/scholarships/' . $opportunity->opportunity_id,
            $opportunity->opportunity_id
        );
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

        if (! OpportunityModerationStatus::isPending($opportunity->moderation_status)) {
            throw new RuntimeException('This scholarship has already been reviewed.');
        }

        return $opportunity;
    }
}
