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

    public function findPubliclyVisible(int $id): ?Opportunity
    {
        $opportunity = Opportunity::with('provider')->find($id);

        return $opportunity && $opportunity->isPubliclyVisible() ? $opportunity : null;
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
