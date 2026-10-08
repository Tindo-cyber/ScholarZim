<?php

namespace App\Services;

use App\Exceptions\InvalidOpportunityTransition;
use App\Models\AcademicQualification;
use App\Models\AcademicSubject;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Models\OpportunityView;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\FormOptions;
use App\Support\NotificationType;
use App\Support\EditImpact;
use App\Support\OpportunityLifecycle;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ProviderTrust;
use App\Support\RoleNames;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Catalogue\ListingScopes;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Validation\ValidationException;

class OpportunityService
{
    /** Recorded as the reviewer of a listing nobody reviewed, so it is plain in every audit trail. */
    public const AUTO_APPROVER = 'system (trusted provider)';

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly AuditService $auditService,
        private readonly ListingRiskChecker $riskChecker,
        private readonly ListingScopes $scopes,
    ) {
    }

    /**
     * Who the listing is published as.
     *
     * provider_name is always the provider's own verified organisation name. It
     * used to be whatever was typed in the "awarding body" box, with other
     * providers' names offered as suggestions, so anyone could publish under
     * another organisation's name. A different name is still allowed - agents
     * and trusts do post for others - but it is kept apart in on_behalf_of and
     * shown as such, never as the publisher.
     *
     * @return array{provider_name: string, on_behalf_of: ?string}
     */
    private function identityAttributes(array $data, User $provider): array
    {
        $typed = trim((string) ($data['provider_display_name'] ?? ''));
        $own = trim((string) $provider->full_name);

        return [
            'provider_name' => $own,
            'on_behalf_of' => ($typed !== '' && strcasecmp($typed, $own) !== 0) ? $typed : null,
        ];
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
            'required_province' => $stringOrNull($data['required_province'] ?? null),
            'minimum_education_level' => $stringOrNull($data['minimum_education_level'] ?? null),
            'target_locality' => $stringOrNull($data['target_locality'] ?? null),
            'target_settlement_type' => $stringOrNull($data['target_settlement_type'] ?? null),
            'requires_results_certificate' => (bool) ($data['requires_results_certificate'] ?? false),
        ];
    }

    /**
     * Providers submit; admins publish. A new post starts PENDING and stays
     * invisible to the public until OpportunityModerationService approves it.
     *
     * The opportunity, its subject requirements and its audit entry all land
     * or none do. saveSubjectRequirements() used to run in the controller,
     * after this method had already returned a committed row - so an invalid
     * subject/qualification pairing or an unsupported grade threw only after
     * the opportunity itself was permanently saved. Both now happen inside
     * the same transaction, and admins are told about the submission only
     * once it has actually committed - telling them about one that then
     * rolled back would be reporting a scholarship that never existed.
     */
    public function create(array $data, User $provider, ?Opportunity $draft = null): Opportunity
    {
        if (! $provider->isActive()) {
            throw new UnauthorizedException(
                'Your provider account must be approved before publishing scholarships.'
            );
        }

        $country = $this->normalizeCountry($data['country'] ?? null);
        $identity = $this->identityAttributes($data, $provider);
        $award = $this->awardAttributes($data);

        // The risk checker reads what the provider wrote (title, description, link),
        // not just the award, and compares the link with who is posting.
        $flags = $this->riskChecker->check(
            $identity + $award + [
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'education_level' => $data['education_level'] ?? null,
                'target_field' => $data['target_field'] ?? null,
            ],
            $provider
        );

        // A trusted provider's new listing goes live at once - unless the checker
        // raised anything, in which case it is reviewed first like everyone else's.
        // Trust is the shortcut; a flag is the reason not to take it.
        $autoApprove = $flags === [] && ProviderTrust::isTrusted($provider);

        $opportunity = DB::transaction(function () use ($data, $provider, $country, $identity, $award, $flags, $autoApprove, $draft) {
            $attributes = [
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
                'created_at' => Carbon::now(),
            ] + $identity + $award + ['risk_flags' => $flags ?: null];

            if ($draft !== null) {
                // Submitting a draft turns that row into the listing rather than making a
                // second one, so there is one history and no orphan. It takes the same legal
                // step any new listing takes - DRAFT -> PENDING - and then, if it earns it,
                // PENDING -> APPROVED below. Everything else (validation, the risk checker,
                // trust, telling the administrators) has just run as for a listing typed from
                // scratch, because this IS that path.
                abort_unless(
                    $draft->isDraft() && $draft->provider_user_id === $provider->user_id,
                    404
                );

                OpportunityLifecycle::assertModeration($draft->moderation_status, OpportunityModerationStatus::PENDING);

                $draft->update($attributes + [
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'rejection_reason' => null,
                ]);

                $opportunity = $draft;
            } else {
                $opportunity = Opportunity::create($attributes);
            }

            $this->saveSubjectRequirements($opportunity, $data['subject_requirements'] ?? []);
            $this->scopes->save($opportunity, $data, $provider);

            $this->auditService->logOrFail(
                $provider->email,
                AuditAction::CREATE_OPPORTUNITY,
                'OPPORTUNITY',
                $opportunity->opportunity_id,
                'Submitted opportunity "' . $opportunity->title . '" for review'
            );

            if ($autoApprove) {
                $this->publishWithoutReview($opportunity);
            }

            return $opportunity;
        });

        Log::info('Opportunity submitted for review', [
            'id' => $opportunity->opportunity_id,
            'by' => $provider->email,
        ]);

        $this->forgetFacetCaches();

        if ($autoApprove) {
            // Live already, so applicants are told now - and the administrators are told
            // it needs looking at AFTER the fact, which is not the same message as
            // "awaiting review".
            $this->notifyAdminsOfAutoPublished($opportunity);
            $this->notifyProviderPublished($opportunity, $provider);
            $this->announcePublished($opportunity);

            return $opportunity;
        }

        // Applicants are NOT told about the post yet - that happens on approval, in
        // OpportunityModerationService. Announcing it here would push unreviewed
        // listings out by email while they are still invisible on the site.
        $this->notifyAdminsOfPendingReview($opportunity);

        return $opportunity;
    }

    /**
     * The one place a listing goes live without an administrator.
     *
     * It still travels PENDING -> APPROVED through OpportunityLifecycle rather than
     * being written straight in as approved: the transition table is what says
     * which moves are legal, and a shortcut that skipped it would be a second,
     * unwritten way for a listing to become public. auto_approved marks it, so it
     * lands in the administrators' "published without review" queue until one of
     * them has looked.
     */
    private function publishWithoutReview(Opportunity $opportunity): void
    {
        OpportunityLifecycle::assertModeration(
            $opportunity->moderation_status,
            OpportunityModerationStatus::APPROVED
        );

        $opportunity->update([
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'reviewed_at' => Carbon::now(),
            'reviewed_by' => self::AUTO_APPROVER,
            'rejection_reason' => null,
            'auto_approved' => true,
            'post_reviewed_at' => null,
            'post_reviewed_by' => null,
        ]);

        $this->auditService->logOrFail(
            self::AUTO_APPROVER,
            AuditAction::PUBLISH_WITHOUT_REVIEW,
            'OPPORTUNITY',
            $opportunity->opportunity_id,
            'Published "' . $opportunity->title . '" without review: provider is trusted and nothing was flagged'
        );
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
     *
     * Same atomicity guarantee as create(): the opportunity's own attributes,
     * its subject requirements, moderation state and audit entry all commit
     * together or not at all. An invalid subject requirement used to be
     * caught only after the opportunity row itself was already saved,
     * leaving a provider's edit half-applied - the title and award changed,
     * the subject rules not.
     */
    public function update(int $opportunityId, array $data, User $provider, string $reason): Opportunity
    {
        $opportunity = $this->findOwnedOrFail($opportunityId, $provider);

        if ($opportunity->isDraft()) {
            throw new \RuntimeException('This listing is still a draft. Save it as a draft, or submit it for review.');
        }

        if ($opportunity->isWithdrawn()) {
            throw InvalidOpportunityTransition::withdrawn();
        }

        [$attributes, $material] = $this->proposedChange($opportunity, $data, $provider);

        $wasPending = OpportunityModerationStatus::isPending($opportunity->moderation_status);
        $wasRejected = OpportunityModerationStatus::isRejected($opportunity->moderation_status);

        $attributes += [
            'deadline' => $data['deadline'] ?? null,
            'last_change_reason' => $reason,
            'updated_at' => Carbon::now(),
        ];

        // What this save does to the listing's place in the review queue.
        //
        // Entering the queue: a material edit takes an APPROVED listing back to
        // PENDING, and ANY save of a REJECTED one is a resubmission - the provider
        // has answered the refusal, whether or not the answer touched a material
        // field. A non-material edit used to leave a rejected listing rejected
        // forever, with nothing telling the provider it still needed resubmitting.
        //
        // Already in the queue: a PENDING listing that is edited stays PENDING.
        // That is not a transition (PENDING -> PENDING is the same state, which is
        // why OpportunityLifecycle has no such entry); asserting one is what made
        // editing a listing before its review throw. It only refreshes the time it
        // joined the queue, so a moderator sees the version they are reviewing.
        $entersQueue = ($material && ! $wasPending) || $wasRejected;
        $refreshesQueue = $material && $wasPending;

        if ($entersQueue) {
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
                // Back in the ordinary queue: whoever approves it next is reviewing it
                // beforehand, so it must not reappear as "published without review".
                'auto_approved' => false,
                'post_reviewed_at' => null,
                'post_reviewed_by' => null,
            ];
        } elseif ($refreshesQueue) {
            $attributes += ['submitted_at' => Carbon::now()];
        }

        $outcome = match (true) {
            $wasRejected => 'resubmitted for review',
            $entersQueue => 'material, back to review',
            $refreshesQueue => 'edited while awaiting review',
            $wasPending => 'minor, still awaiting review',
            default => 'minor, stays live',
        };

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

        $opportunity = DB::transaction(function () use ($opportunity, $attributes, $data, $provider, $reason, $outcome, $changes) {
            $opportunity->update($attributes);

            $this->saveSubjectRequirements($opportunity, $data['subject_requirements'] ?? []);
            $this->scopes->save($opportunity, $data, $provider);

            $this->auditService->logOrFail(
                $provider->email,
                AuditAction::UPDATE_OPPORTUNITY,
                'OPPORTUNITY',
                $opportunity->opportunity_id,
                'Updated "' . $opportunity->title . '" (' . $outcome . '): ' . $reason,
                $changes + ['reason' => $reason]
            );

            return $opportunity;
        });

        $this->forgetFacetCaches();

        // Told whenever a listing moves INTO the queue from any other state - an
        // approved one taken back, or a rejected one resubmitted. Not when one that
        // is already waiting is edited: it is already on their list.
        if ($entersQueue) {
            $this->notifyAdminsOfPendingReview($opportunity);
        }

        return $opportunity;
    }

    /**
     * The attributes an edit would save, and whether it is a material change.
     *
     * Shared by update(), which applies it, and editImpact(), which only reports
     * it, so the warning shown on the edit page can never disagree with what
     * saving then does.
     *
     * @return array{0: array<string, mixed>, 1: bool} [attributes, material]
     */
    private function proposedChange(Opportunity $opportunity, array $data, User $provider): array
    {
        $country = $this->normalizeCountry($data['country'] ?? null);
        $attributes = [
            'title' => $data['title'] ?? $opportunity->title,
            'description' => $data['description'] ?? null,
            'education_level' => $data['education_level'] ?? null,
            'funding_type' => $data['funding_type'] ?? null,
            'country' => $country,
            'target_country' => $country,
            'target_field' => filled($data['target_field'] ?? null) ? trim($data['target_field']) : null,
        ] + $this->identityAttributes($data, $provider) + $this->awardAttributes($data);

        // Recomputed from what is being saved now - a provider who removes the wording
        // that tripped a flag should lose the flag - but the flags that come from
        // elsewhere (student reports) are kept.
        $attributes['risk_flags'] = $this->riskChecker->withStickyFlags(
            $opportunity->risk_flags,
            $this->riskChecker->check($attributes, $provider)
        ) ?: null;

        $material = OpportunityLifecycle::isMaterialChange($opportunity, $attributes)
            // Bringing a deadline forward cuts applicants off early, so it is
            // material even though pushing one back is not.
            || OpportunityLifecycle::shortensDeadline($opportunity, $data['deadline'] ?? null)
            || $this->subjectRequirementsChanged($opportunity, $data['subject_requirements'] ?? [])
            // Who the listing is open to decides who is eligible, so it is material too.
            || $this->scopes->changed($opportunity, $data);

        return [$attributes, $material];
    }

    /**
     * What saving these values would do to the listing's place in review, as an
     * EditImpact outcome. Writes nothing.
     */
    public function editImpact(int $opportunityId, array $data, User $provider): string
    {
        $opportunity = $this->findOwnedOrFail($opportunityId, $provider);

        if ($opportunity->isDraft()) {
            throw new \RuntimeException('A draft has no review to predict. Submit it to find out.');
        }

        if ($opportunity->isWithdrawn()) {
            throw InvalidOpportunityTransition::withdrawn();
        }

        [, $material] = $this->proposedChange($opportunity, $data, $provider);

        return match (true) {
            OpportunityModerationStatus::isRejected($opportunity->moderation_status) => EditImpact::RESUBMIT,
            OpportunityModerationStatus::isPending($opportunity->moderation_status) => EditImpact::AWAITING_REVIEW,
            $material => EditImpact::BACK_TO_REVIEW,
            default => EditImpact::STAYS_LIVE,
        };
    }

    /**
     * A narrower action than update(): only the deadline moves, so the listing
     * stays live and does not need to go back through moderation.
     */
    public function extendDeadline(int $opportunityId, User $provider, string $newDeadline, string $reason): Opportunity
    {
        $opportunity = $this->findOwnedOrFail($opportunityId, $provider);

        if ($opportunity->isDraft()) {
            throw new \RuntimeException('A draft has not been published, so its deadline cannot be extended. Change it in the draft.');
        }

        if ($opportunity->isWithdrawn()) {
            throw InvalidOpportunityTransition::withdrawn();
        }

        // A rolling listing has no deadline to move. "Extending" it would add one,
        // which narrows the listing rather than extending it, so it is refused
        // here as well as hidden in the dashboard.
        if ($opportunity->deadline === null) {
            throw new \RuntimeException('This listing has a rolling intake, so there is no deadline to extend. Edit the listing if you want to set one.');
        }

        if (Carbon::parse($newDeadline)->lt($opportunity->deadline)) {
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

        $previousDeadline = $opportunity->deadline;

        // The new date and its audit entry land together or not at all, as in
        // create() and update(): a deadline moved with no record of who moved it,
        // or a record of a move that never happened, are both worse than failing.
        DB::transaction(function () use ($opportunity, $attributes, $provider, $reason, $previousDeadline) {
            $opportunity->update($attributes);

            $this->auditService->logOrFail(
                $provider->email,
                AuditAction::EXTEND_OPPORTUNITY_DEADLINE,
                'OPPORTUNITY',
                $opportunity->opportunity_id,
                'Extended deadline for "' . $opportunity->title . '" to ' . $opportunity->deadline->format('d M Y') . ': ' . $reason,
                [
                    'old' => ['deadline' => $previousDeadline?->toDateString()],
                    'new' => ['deadline' => $opportunity->deadline->toDateString()],
                    'reason' => $reason,
                ]
            );
        });

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

        if ($opportunity->isDraft()) {
            throw new \RuntimeException('A draft is discarded, not withdrawn: nobody has seen it.');
        }

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

    private function notifyAdminsOfAutoPublished(Opportunity $opportunity): void
    {
        $admins = User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->get();

        $this->notificationService->notifyMany(
            $admins,
            NotificationType::SCHOLARSHIP_PENDING_REVIEW,
            'Published without review by a trusted provider: "' . $opportunity->title . '". Take a look when you can.',
            '/admin/auto-published',
            $opportunity->opportunity_id
        );
    }

    private function notifyProviderPublished(Opportunity $opportunity, User $provider): void
    {
        $this->notificationService->notifyUser(
            $provider,
            NotificationType::SCHOLARSHIP_APPROVED,
            'Your scholarship "' . $opportunity->title . '" is now live.',
            '/scholarships/' . $opportunity->opportunity_id,
            $opportunity->opportunity_id
        );
    }

    /**
     * Tell applicants a listing is public. Only ever called once it actually is:
     * on approval by an administrator, or on publication by a trusted provider.
     */
    public function announcePublished(Opportunity $opportunity): void
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
        return Opportunity::with([
            'provider',
            'subjectRequirements.subject',
            'subjectRequirements.qualification',
        ])->publiclyVisible()->find($id);
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
            ->notDraft()
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
     * The filter facets, which are the only thing about the catalogue that is
     * cached. ScholarFit rankings are computed on demand, so a listing changing
     * needs nothing done to them.
     */
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

    /**
     * Whether the submitted subject requirements differ from what is stored.
     *
     * Subject requirements live on a separate table, so OpportunityLifecycle::
     * isMaterialChange() does not see them. Changing the subject bar changes
     * who is eligible, so it is material regardless of which axis moved.
     */
    private function subjectRequirementsChanged(Opportunity $opportunity, array $newRequirements): bool
    {
        $current = $opportunity->subjectRequirements()
            ->orderby('id')
            ->get(['subject_id', 'minimum_grade'])
            ->map(fn ($r) => [
                'subject_id' => (int) $r->subject_id,
                'minimum_grade' => $r->minimum_grade,
            ])
            ->values()
            ->toArray();

        $incoming = [];

        foreach ($newRequirements as $req) {
            $incoming[] = [
                'subject_id' => (int) $req['subject_id'],
                // Null-coalesced deliberately: the previous read of this key
                // raised an undefined-index warning on every save, because
                // the form has never posted it.
                'minimum_grade' => $req['minimum_grade'] ?? null,
            ];
        }

        return $current !== $incoming;
    }

    /**
     * Brings the listing's subject requirements into line with the submitted
     * set. Moved here from OpportunityController so create()/update() can
     * run it inside the same transaction as the opportunity write it used
     * to follow - see the class docblocks above both methods.
     *
     * Upserted on (opportunity_id, subject_id) - the key the table enforces -
     * so an edit updates the rows that are still there and removes only the
     * ones the provider actually deleted.
     *
     * The bug this replaces was in the form rather than here: existing rows
     * rendered their minimum grade as a readonly input with no `name`, so it
     * was never submitted, `minimum_grade` was nullable, and this method
     * deleted and reinserted the lot. Editing a listing's title therefore
     * reset every subject rule to "any grade". The form now posts the grade
     * as an editable named field, and this method no longer destroys a row it
     * is about to recreate.
     *
     * A grade the requirement's own qualification does not award is rejected:
     * a bar nobody can be measured against is not a rule. Thrown inside the
     * caller's transaction, so an invalid requirement rolls the opportunity
     * write back too rather than leaving it saved without its rules.
     */
    private function saveSubjectRequirements(Opportunity $opportunity, array $requirements): void
    {
        $keptIds = [];
        $position = 0;

        foreach ($requirements as $index => $req) {
            // The row's place in the list, for the message: "Row 2" is the second row
            // the provider sees, whatever key the form happened to post it under.
            $position++;

            $qualification = AcademicQualification::find($req['qualification_id'] ?? null);
            $subject = AcademicSubject::find($req['subject_id'] ?? null);

            if ($qualification === null || $subject === null
                || (int) $subject->qualification_id !== (int) $qualification->id) {
                throw ValidationException::withMessages([
                    "subject_requirements.$index.subject_id" => "Row {$position}: choose a subject offered under the selected qualification.",
                ]);
            }

            $grade = null;

            if (filled($req['minimum_grade'] ?? null)) {
                // Checked against the subject's own scale where it has one. A
                // requirement of "6 or better" is meaningful on a Cambridge
                // IGCSE 9-1 syllabus and meaningless on an A*-G one, even
                // though both sit under Cambridge IGCSE.
                $grade = $subject->canonicalGrade($req['minimum_grade']);

                if ($grade === null) {
                    $awardedBy = $subject->hasOwnScheme() ? $subject->name : $qualification->name;

                    throw ValidationException::withMessages([
                        "subject_requirements.$index.minimum_grade" => "Row {$position}: choose a grade that ".$awardedBy
                            .' awards ('.implode(', ', $subject->grades()).').',
                    ]);
                }
            }

            $requirement = OpportunitySubjectRequirement::updateOrCreate(
                [
                    'opportunity_id' => $opportunity->opportunity_id,
                    'subject_id' => $subject->id,
                ],
                [
                    'qualification_id' => $qualification->id,
                    'minimum_grade' => $grade,
                ]
            );

            $keptIds[] = $requirement->id;
        }

        $opportunity->subjectRequirements()->whereNotIn('id', $keptIds)->delete();
    }
}
