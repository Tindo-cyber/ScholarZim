<?php

namespace App\Services;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\FormOptions;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\RoleNames;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\UnauthorizedException;

class OpportunityService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly AuditService $auditService,
    ) {
    }

    /**
     * Providers submit; admins publish. A new post starts PENDING and stays
     * invisible to the public until OpportunityModerationService approves it.
     */
    public function create(array $data, User $provider): Opportunity
    {
        if (! $provider->isActive()) {
            throw new UnauthorizedException(
                'Your provider account must be approved before publishing scholarships.'
            );
        }

        $country = $this->normalizeCountry($data['country'] ?? null);
        $displayName = trim((string) ($data['provider_display_name'] ?? ''));

        $opportunity = Opportunity::create([
            'provider_user_id' => $provider->user_id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'education_level' => $data['education_level'] ?? null,
            'funding_type' => $data['funding_type'] ?? null,
            'country' => $country,
            'target_country' => $country,
            'target_field' => filled($data['target_field'] ?? null) ? trim($data['target_field']) : null,
            'deadline' => $data['deadline'] ?? null,
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::PENDING,
            'submitted_at' => Carbon::now(),
            'provider_name' => $displayName !== '' ? $displayName : $provider->full_name,
            'created_at' => Carbon::now(),
        ]);

        $this->auditService->log(
            $provider->email,
            AuditAction::CREATE_OPPORTUNITY,
            'OPPORTUNITY',
            $opportunity->opportunity_id,
            'Submitted opportunity "' . $opportunity->title . '" for review'
        );

        Log::info('Opportunity submitted for review', [
            'id' => $opportunity->opportunity_id,
            'by' => $provider->email,
        ]);

        $this->forgetFacetCaches();

        // Applicants are NOT told about the post yet - that happens on approval, in
        // OpportunityModerationService. Announcing it here would push unreviewed
        // listings out by email while they are still invisible on the site.
        $this->notifyAdminsOfPendingReview($opportunity);

        return $opportunity;
    }

    /**
     * Providers may revise a listing after it is posted. Since the content
     * changed, it goes back through moderation before it is public again -
     * the same trust boundary a brand-new post crosses in create().
     */
    public function update(int $opportunityId, array $data, User $provider, string $reason): Opportunity
    {
        $opportunity = $this->findOwnedOrFail($opportunityId, $provider);

        if ($opportunity->isWithdrawn()) {
            throw new \RuntimeException('This scholarship has been withdrawn and can no longer be edited.');
        }

        $wasApproved = OpportunityModerationStatus::isApproved($opportunity->moderation_status);
        $country = $this->normalizeCountry($data['country'] ?? null);
        $displayName = trim((string) ($data['provider_display_name'] ?? ''));

        $opportunity->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'education_level' => $data['education_level'] ?? null,
            'funding_type' => $data['funding_type'] ?? null,
            'country' => $country,
            'target_country' => $country,
            'target_field' => filled($data['target_field'] ?? null) ? trim($data['target_field']) : null,
            'deadline' => $data['deadline'] ?? null,
            'provider_name' => $displayName !== '' ? $displayName : $provider->full_name,
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::PENDING,
            'submitted_at' => Carbon::now(),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'rejection_reason' => null,
            'last_change_reason' => $reason,
            'updated_at' => Carbon::now(),
        ]);

        $this->auditService->log(
            $provider->email,
            AuditAction::UPDATE_OPPORTUNITY,
            'OPPORTUNITY',
            $opportunity->opportunity_id,
            'Updated "' . $opportunity->title . '": ' . $reason
        );

        $this->forgetFacetCaches();

        if ($wasApproved) {
            $this->notifyAdminsOfPendingReview($opportunity);
        }

        return $opportunity;
    }

    /**
     * A narrower action than update(): only the deadline moves, so the listing
     * stays live and does not need to go back through moderation.
     */
    public function extendDeadline(int $opportunityId, User $provider, string $newDeadline, string $reason): Opportunity
    {
        $opportunity = $this->findOwnedOrFail($opportunityId, $provider);

        if ($opportunity->isWithdrawn()) {
            throw new \RuntimeException('This scholarship has been withdrawn and can no longer be edited.');
        }

        if ($opportunity->deadline && Carbon::parse($newDeadline)->lt($opportunity->deadline)) {
            throw new \RuntimeException('The new deadline must be on or after the current deadline.');
        }

        $opportunity->update([
            'deadline' => $newDeadline,
            'status' => OpportunityStatus::ACTIVE,
            'last_change_reason' => $reason,
            'updated_at' => Carbon::now(),
        ]);

        $this->auditService->log(
            $provider->email,
            AuditAction::EXTEND_OPPORTUNITY_DEADLINE,
            'OPPORTUNITY',
            $opportunity->opportunity_id,
            'Extended deadline for "' . $opportunity->title . '" to ' . $opportunity->deadline->format('d M Y') . ': ' . $reason
        );

        $this->forgetFacetCaches();

        $this->notifyApplicants(
            $opportunity,
            NotificationType::SCHOLARSHIP_UPDATED,
            'The deadline for "' . $opportunity->title . '" was extended to ' . $opportunity->deadline->format('d M Y') . '.'
        );

        return $opportunity;
    }

    /**
     * Soft delete: the row stays (applications reference it), the listing just
     * drops out of scopePubliclyVisible() because moderation_status is no
     * longer APPROVED.
     */
    public function delete(int $opportunityId, User $provider, string $reason): Opportunity
    {
        $opportunity = $this->findOwnedOrFail($opportunityId, $provider);

        if ($opportunity->isWithdrawn()) {
            throw new \RuntimeException('This scholarship has already been withdrawn.');
        }

        $opportunity->update([
            'moderation_status' => OpportunityModerationStatus::WITHDRAWN,
            'last_change_reason' => $reason,
            'updated_at' => Carbon::now(),
        ]);

        $this->auditService->log(
            $provider->email,
            AuditAction::DELETE_OPPORTUNITY,
            'OPPORTUNITY',
            $opportunity->opportunity_id,
            'Withdrew "' . $opportunity->title . '": ' . $reason
        );

        $this->forgetFacetCaches();

        $this->notifyApplicants(
            $opportunity,
            NotificationType::SCHOLARSHIP_WITHDRAWN,
            'The scholarship "' . $opportunity->title . '" was withdrawn by the provider: ' . $reason
        );

        return $opportunity;
    }

    public function findOwnedOrFail(int $opportunityId, User $provider): Opportunity
    {
        $opportunity = Opportunity::where('opportunity_id', $opportunityId)
            ->where('provider_user_id', $provider->user_id)
            ->first();

        if (! $opportunity) {
            throw new \RuntimeException('Scholarship not found.');
        }

        return $opportunity;
    }

    /** Tells applicants who already applied about a change to the listing, for transparency. */
    private function notifyApplicants(Opportunity $opportunity, string $type, string $message): void
    {
        $applicantIds = $opportunity->applications()->pluck('user_id');

        if ($applicantIds->isEmpty()) {
            return;
        }

        $applicants = User::whereIn('user_id', $applicantIds)->get();

        $this->notificationService->notifyMany(
            $applicants,
            $type,
            $message,
            '/scholarships/' . $opportunity->opportunity_id,
            $opportunity->opportunity_id
        );
    }

    private function notifyAdminsOfPendingReview(Opportunity $opportunity): void
    {
        $admins = User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->get();

        $this->notificationService->notifyMany(
            $admins,
            NotificationType::SCHOLARSHIP_PENDING_REVIEW,
            'New scholarship awaiting review: "' . $opportunity->title . '".',
            '/admin/dashboard#scholarship-moderation',
            $opportunity->opportunity_id
        );
    }

    /** Faceted public search. Returns a paginator so listing pages can page. */
    public function search(array $filters, int $perPage = 12)
    {
        return Opportunity::query()
            ->publiclyVisible()
            ->matchingFilters($filters)
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Unpaginated variant, used by the recommendation engine. */
    public function searchAll(array $filters = [])
    {
        return Opportunity::query()
            ->publiclyVisible()
            ->matchingFilters($filters)
            ->orderByDesc('created_at')
            ->get();
    }

    public function featured(int $limit = 6)
    {
        $safeLimit = max(1, min($limit, 24));

        return Opportunity::query()
            ->publiclyVisible()
            ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END, deadline ASC')
            ->orderByDesc('created_at')
            ->limit($safeLimit)
            ->get();
    }

    public function countActive(): int
    {
        return Opportunity::query()->publiclyVisible()->count();
    }

    public function countUpcomingDeadlines(int $withinDays = 30): int
    {
        return Opportunity::query()
            ->publiclyVisible()
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [
                Carbon::today()->toDateString(),
                Carbon::today()->addDays($withinDays)->toDateString(),
            ])
            ->count();
    }

    /**
     * Same rules as search(): approved, open, and not past its deadline. An
     * archived (closed/expired) listing is 404, not just absent from search -
     * it should not be directly viewable or applyable-to by URL either.
     */
    public function findPubliclyVisible(int $id): ?Opportunity
    {
        return Opportunity::with('provider')->publiclyVisible()->find($id);
    }

    /** @return array<int, string> */
    public function providerNames(): array
    {
        return Cache::remember('opportunities.provider_names', now()->addMinutes(15), fn () => Opportunity::query()
            ->approved()
            ->whereNotNull('provider_name')
            ->where('provider_name', '<>', '')
            ->distinct()
            ->orderBy('provider_name')
            ->pluck('provider_name')
            ->all());
    }

    /** @return array<int, string> */
    public function targetFields(): array
    {
        return Cache::remember('opportunities.target_fields', now()->addMinutes(15), fn () => Opportunity::query()
            ->approved()
            ->whereNotNull('target_field')
            ->where('target_field', '<>', '')
            ->distinct()
            ->orderBy('target_field')
            ->pluck('target_field')
            ->all());
    }

    public function forProvider(User $provider)
    {
        return Opportunity::where('provider_user_id', $provider->user_id)
            ->withCount('applications')
            ->orderByDesc('created_at')
            ->get();
    }

    public function forgetFacetCaches(): void
    {
        Cache::forget('opportunities.provider_names');
        Cache::forget('opportunities.target_fields');
    }

    private function normalizeCountry(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : FormOptions::DEFAULT_COUNTRY;
    }
}
