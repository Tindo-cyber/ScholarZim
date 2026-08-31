<?php

namespace App\Services;

use App\Exceptions\InvalidOpportunityTransition;
use App\Models\Opportunity;
use App\Models\OpportunityView;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\FormOptions;
use App\Support\NotificationType;
use App\Support\OpportunityLifecycle;
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
        private readonly RecommendationService $recommendations,
    ) {
    }

    /**
     * Award and hard-eligibility columns, normalised from a validated request.
     *
     * Shared by create() and update() so a listing cannot end up with an amount
     * on one path and not the other. An empty string means "not stated" and is
     * stored as NULL, because a blank rule must never read as a rule of zero.
     */
    private function awardAttributes(array $data): array
    {
        $intOrNull = static fn (mixed $value): ?int => filled($value) ? (int) $value : null;

        $stringOrNull = static function (mixed $value): ?string {
            $trimmed = trim((string) $value);

            return $trimmed !== '' ? $trimmed : null;
        };

        $amount = filled($data['award_amount'] ?? null) ? (float) $data['award_amount'] : null;

        return [
            'award_amount' => $amount,
            // A currency without an amount is meaningless, so it is only kept
            // alongside one.
            'award_currency' => $amount === null
                ? null
                : ($stringOrNull($data['award_currency'] ?? null) ?? FormOptions::DEFAULT_CURRENCY),
            'award_slots' => $intOrNull($data['award_slots'] ?? null),
            'is_renewable' => (bool) ($data['is_renewable'] ?? false),
            'external_url' => $stringOrNull($data['external_url'] ?? null),
            'min_academic_points' => $intOrNull($data['min_academic_points'] ?? null),
            'max_age' => $intOrNull($data['max_age'] ?? null),
            'required_citizenship' => $stringOrNull($data['required_citizenship'] ?? null),
            'required_province' => $stringOrNull($data['required_province'] ?? null),
            'requires_results_certificate' => (bool) ($data['requires_results_certificate'] ?? false),
        ];
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
        ] + $this->awardAttributes($data));

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
     * Providers may revise a listing after it is posted.
     *
     * Whether that costs them their approval depends on what they changed. An
     * edit that alters what is being offered or who may have it - the fields
     * OpportunityLifecycle calls material - goes back through moderation, on the
     * same trust boundary a brand-new post crosses in create(). A presentational
     * edit, or extending a deadline, leaves an approved listing live.
     *
     * The old rule sent every edit back to the queue, which meant fixing a typo
     * un-published a live scholarship until an administrator got to it. That
     * teaches providers to leave mistakes alone, which is the opposite of what
     * moderation is for - and it was already inconsistent, since extendDeadline()
     * had carved out exactly this exception for itself.
     */
    public function update(int $opportunityId, array $data, User $provider, string $reason): Opportunity
    {
        $opportunity = $this->findOwnedOrFail($opportunityId, $provider);

        if ($opportunity->isWithdrawn()) {
            throw InvalidOpportunityTransition::withdrawn();
        }

        $country = $this->normalizeCountry($data['country'] ?? null);
        $displayName = trim((string) ($data['provider_display_name'] ?? ''));

        $attributes = [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'education_level' => $data['education_level'] ?? null,
            'funding_type' => $data['funding_type'] ?? null,
            'country' => $country,
            'target_country' => $country,
            'target_field' => filled($data['target_field'] ?? null) ? trim($data['target_field']) : null,
        ] + $this->awardAttributes($data);

        $wasApproved = OpportunityModerationStatus::isApproved($opportunity->moderation_status);
        $material = OpportunityLifecycle::isMaterialChange($opportunity, $attributes)
            // Bringing a deadline forward cuts applicants off early, so it is
            // material even though pushing one back is not.
            || OpportunityLifecycle::shortensDeadline($opportunity, $data['deadline'] ?? null);

        $attributes += [
            'deadline' => $data['deadline'] ?? null,
            'provider_name' => $displayName !== '' ? $displayName : $provider->full_name,
            'last_change_reason' => $reason,
            'updated_at' => Carbon::now(),
        ];

        if ($material) {
            OpportunityLifecycle::assertModeration(
                $opportunity->moderation_status,
                OpportunityModerationStatus::PENDING
            );

            $attributes += [
                'moderation_status' => OpportunityModerationStatus::PENDING,
                'submitted_at' => Carbon::now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
                'rejection_reason' => null,
            ];
        }

        // A closed listing reopens only if its deadline is genuinely in the
        // future again. Editing one used to set it ACTIVE unconditionally, which
        // said "open" about a listing whose deadline had already passed.
        if ($opportunity->isClosed() && filled($attributes['deadline'])
            && Carbon::parse($attributes['deadline'])->gte(Carbon::today())) {
            OpportunityLifecycle::assertPublication($opportunity->status, OpportunityStatus::ACTIVE);
            $attributes['status'] = OpportunityStatus::ACTIVE;
        }

        // Diffed before the write, so the entry names the fields that moved and
        // what they moved from. A moderator reviewing a listing that came back
        // to the queue needs to see the change, not re-read the whole listing.
        $changes = $this->auditService->diff(
            $opportunity->only(array_keys($attributes)),
            $attributes
        );

        $opportunity->update($attributes);

        $this->auditService->log(
            $provider->email,
            AuditAction::UPDATE_OPPORTUNITY,
            'OPPORTUNITY',
            $opportunity->opportunity_id,
            'Updated "' . $opportunity->title . '" (' . ($material ? 'material, back to review' : 'minor, stays live')
                . '): ' . $reason,
            $changes + ['reason' => $reason]
        );

        $this->forgetFacetCaches();

        if ($material && $wasApproved) {
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
            throw InvalidOpportunityTransition::withdrawn();
        }

        if ($opportunity->deadline && Carbon::parse($newDeadline)->lt($opportunity->deadline)) {
            throw new \RuntimeException('The new deadline must be on or after the current deadline.');
        }

        $attributes = [
            'deadline' => $newDeadline,
            'last_change_reason' => $reason,
            'updated_at' => Carbon::now(),
        ];

        // Reopening is the one thing an extension does to the lifecycle, and it
        // only applies to a listing the deadline had closed. Setting ACTIVE
        // unconditionally, as this used to, would also have reopened a listing
        // the provider had withdrawn.
        if ($opportunity->isClosed() && Carbon::parse($newDeadline)->gte(Carbon::today())) {
            OpportunityLifecycle::assertPublication($opportunity->status, OpportunityStatus::ACTIVE);
            $attributes['status'] = OpportunityStatus::ACTIVE;
        }

        $opportunity->update($attributes);

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

        // Publication axis only. The administrator's verdict is left exactly as
        // it was, so the platform still knows whether this listing had passed
        // review - which withdrawing used to erase.
        OpportunityLifecycle::assertPublication($opportunity->status, OpportunityStatus::WITHDRAWN);

        $opportunity->update([
            'status' => OpportunityStatus::WITHDRAWN,
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

    /**
     * Faceted public search. Returns a paginator so listing pages can page.
     *
     * The ordering arrives in $filters['sort'] straight off the query string;
     * scopeSorted() is the only thing that reads it and falls back to the
     * default for anything it does not recognise.
     */
    public function search(array $filters, int $perPage = 12)
    {
        return Opportunity::query()
            ->publiclyVisible()
            ->matchingFilters($filters)
            ->sorted($filters['sort'] ?? FormOptions::DEFAULT_SORT)
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Unpaginated variant, used by the recommendation engine and alert job. */
    public function searchAll(array $filters = [])
    {
        return Opportunity::query()
            ->publiclyVisible()
            ->matchingFilters($filters)
            ->sorted($filters['sort'] ?? FormOptions::DEFAULT_SORT)
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

    /**
     * Counts a public view of a listing.
     *
     * Two writes on purpose: the running total on the listing keeps the cheap
     * "1,204 views" figure, and the per-day row behind it is what the provider
     * funnel plots. The daily row is upserted so concurrent readers cannot lose
     * a count to a lost update.
     */
    public function recordView(Opportunity $opportunity): void
    {
        $today = Carbon::today()->toDateString();

        try {
            Opportunity::where('opportunity_id', $opportunity->opportunity_id)->increment('view_count');

            OpportunityView::query()->upsert(
                [[
                    'opportunity_id' => $opportunity->opportunity_id,
                    'viewed_on' => $today,
                    'views' => 1,
                ]],
                ['opportunity_id', 'viewed_on'],
                // Raw increment rather than a read-modify-write: two viewers on
                // the same page must not overwrite each other's count.
                ['views' => \Illuminate\Support\Facades\DB::raw('views + 1')]
            );
        } catch (\Throwable $e) {
            // A view counter is never worth failing a page render over.
            Log::debug('View counter write failed', [
                'opportunity' => $opportunity->opportunity_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Listings that look like the one under review: same awarding body, same
     * deadline, and a title that starts the same way. Shown to the moderator as a
     * prompt to look, never as an automatic refusal - two intakes of the same
     * annual bursary are a legitimate pair of rows.
     *
     * @return \Illuminate\Support\Collection<int, Opportunity>
     */
    public function findPotentialDuplicates(Opportunity $opportunity)
    {
        $titlePrefix = mb_substr(trim((string) $opportunity->title), 0, 20);

        if ($titlePrefix === '') {
            return collect();
        }

        $like = str_replace(['%', '_'], ['\%', '\_'], $titlePrefix) . '%';

        return Opportunity::query()
            ->where('opportunity_id', '!=', $opportunity->opportunity_id)
            ->where('moderation_status', '!=', OpportunityModerationStatus::REJECTED)
            ->where(function ($q) use ($opportunity, $like) {
                $q->where('title', 'like', $like);

                // The "same body, same closing date" arm only means anything when
                // both are actually recorded.
                if (filled($opportunity->provider_name) && $opportunity->deadline !== null) {
                    $q->orWhere(function ($inner) use ($opportunity) {
                        $inner->where('provider_name', $opportunity->provider_name)
                            ->whereDate('deadline', $opportunity->deadline);
                    });
                }
            })
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();
    }

    /**
     * Everything cached about the catalogue: the filter facets, and every
     * applicant's ScholarFit ranking, which was computed against the set of
     * listings that just changed.
     */
    public function forgetFacetCaches(): void
    {
        Cache::forget('opportunities.provider_names');
        Cache::forget('opportunities.target_fields');

        $this->recommendations->invalidateCatalog();
    }

    private function normalizeCountry(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : FormOptions::DEFAULT_COUNTRY;
    }
}
