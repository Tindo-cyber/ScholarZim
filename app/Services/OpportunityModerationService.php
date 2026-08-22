<?php

namespace App\Services;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\RoleNames;
use Illuminate\Support\Carbon;
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

    public function approve(int $opportunityId, User $admin): Opportunity
    {
        $opportunity = $this->requirePending($opportunityId);

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
        $opportunity = $this->requirePending($opportunityId);

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

    private function requirePending(int $opportunityId): Opportunity
    {
        $opportunity = Opportunity::with('provider')->findOrFail($opportunityId);

        if (! OpportunityModerationStatus::isPending($opportunity->moderation_status)) {
            throw new RuntimeException('This scholarship has already been reviewed.');
        }

        return $opportunity;
    }
}
