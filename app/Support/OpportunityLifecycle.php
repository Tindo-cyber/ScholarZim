<?php

namespace App\Support;

use App\Exceptions\InvalidOpportunityTransition;
use App\Models\Opportunity;

/**
 * The one place that decides what may happen to a listing next.
 *
 * A listing has two independent states, and the confusion this class exists to
 * end is that they used to be stored in one column:
 *
 *   publication - ACTIVE, CLOSED, WITHDRAWN. The provider's axis, plus the
 *   deadline. Says whether the listing is being offered at all.
 *
 *   moderation - PENDING, APPROVED, REJECTED. The administrator's axis. Says
 *   whether the platform is willing to show it.
 *
 * Neither alone decides visibility, which is exactly why they should not share a
 * column: publicly visible means ACTIVE *and* APPROVED *and* inside the
 * deadline, and every one of those three can change without the other two.
 */
final class OpportunityLifecycle
{
    /**
     * Publication transitions, and who drives them.
     *
     * WITHDRAWN is terminal. That is a deliberate product decision rather than a
     * technical limit: applicants have been notified a listing was taken down,
     * and quietly putting it back would make that notice a lie. A provider who
     * changes their mind posts again, which correctly sends the listing through
     * moderation as a new submission.
     */
    private const PUBLICATION_TRANSITIONS = [
        OpportunityStatus::ACTIVE => [OpportunityStatus::CLOSED, OpportunityStatus::WITHDRAWN],
        // Reopened only by extending the deadline, which is what CLOSED means.
        OpportunityStatus::CLOSED => [OpportunityStatus::ACTIVE, OpportunityStatus::WITHDRAWN],
        OpportunityStatus::WITHDRAWN => [],
    ];

    /**
     * Moderation transitions. A verdict is reached from PENDING and nowhere
     * else, so an administrator cannot silently overturn a decision that has
     * already been communicated; a provider's edit is what returns a listing to
     * the queue, and that is a publication-side action, handled below.
     */
    private const MODERATION_TRANSITIONS = [
        OpportunityModerationStatus::PENDING => [
            OpportunityModerationStatus::APPROVED,
            OpportunityModerationStatus::REJECTED,
        ],
        OpportunityModerationStatus::APPROVED => [OpportunityModerationStatus::PENDING],
        OpportunityModerationStatus::REJECTED => [OpportunityModerationStatus::PENDING],
    ];

    /**
     * Fields whose change alters what a listing is offering or who may have it.
     *
     * Editing one of these sends the listing back to the queue. Everything not
     * listed is presentational and leaves an approved listing live.
     *
     * The split matters because the alternative - which is what the code did -
     * is to re-moderate every edit, so correcting a typo in a description
     * un-publishes a live scholarship until an administrator gets to it. That
     * teaches providers not to fix mistakes, which is the opposite of what
     * moderation is for.
     *
     * Title and description are material despite being "just text": they are the
     * listing's actual claims, and rewriting them after approval is the obvious
     * way to get something past review that would not have passed it.
     */
    private const MATERIAL_FIELDS = [
        'title',
        'description',
        'education_level',
        'target_field',
        'funding_type',
        'country',
        'target_country',
        'target_district',
        'target_locality',
        'min_academic_points',
        'max_age',
        'required_citizenship',
        'required_province',
        'requires_results_certificate',
        'award_amount',
        'award_currency',
        'award_slots',
        'is_renewable',
    ];

    private function __construct()
    {
    }

    /** Whether a listing may move to this publication status. */
    public static function canTransitionPublication(?string $from, string $to): bool
    {
        $current = self::normalisePublication($from);

        return in_array($to, self::PUBLICATION_TRANSITIONS[$current] ?? [], true);
    }

    /** @throws InvalidOpportunityTransition */
    public static function assertPublication(?string $from, string $to): void
    {
        if (! self::canTransitionPublication($from, $to)) {
            throw InvalidOpportunityTransition::publication($from, $to);
        }
    }

    public static function canTransitionModeration(?string $from, string $to): bool
    {
        $current = self::normaliseModeration($from);

        return in_array($to, self::MODERATION_TRANSITIONS[$current] ?? [], true);
    }

    /** @throws InvalidOpportunityTransition */
    public static function assertModeration(?string $from, string $to): void
    {
        if (! self::canTransitionModeration($from, $to)) {
            throw InvalidOpportunityTransition::moderation($from, $to);
        }
    }

    /**
     * The single rule behind "is this listing on the public site?".
     *
     * All three conditions, together. Kept next to the transitions rather than
     * only as a query scope so the same answer is available for a model already
     * in memory - a listing that passes this and a query that returns it must
     * never be able to disagree.
     */
    public static function isPubliclyVisible(Opportunity $opportunity): bool
    {
        return OpportunityStatus::isActive($opportunity->status)
            && OpportunityModerationStatus::isApproved($opportunity->moderation_status)
            && ! self::deadlineHasPassed($opportunity);
    }

    public static function deadlineHasPassed(Opportunity $opportunity): bool
    {
        return $opportunity->deadline !== null
            && $opportunity->deadline->lt(\Illuminate\Support\Carbon::today());
    }

    /** Whether a listing may still take new applications. */
    public static function acceptsApplications(Opportunity $opportunity): bool
    {
        return self::isPubliclyVisible($opportunity);
    }

    /**
     * Whether an edit changes what was approved, and so has to be re-approved.
     *
     * @param  array<string, mixed>  $changes  attribute => new value
     */
    public static function isMaterialChange(Opportunity $opportunity, array $changes): bool
    {
        foreach ($changes as $field => $value) {
            if (! in_array($field, self::MATERIAL_FIELDS, true)) {
                continue;
            }

            if (self::differs($opportunity->getAttribute($field), $value)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public static function materialFields(): array
    {
        return self::MATERIAL_FIELDS;
    }

    /**
     * Bringing a deadline forward cuts applicants off early, so it is material
     * even though extending one is not. extendDeadline() already refuses to move
     * a deadline backwards; this keeps the general edit path honest about it.
     */
    public static function shortensDeadline(Opportunity $opportunity, mixed $newDeadline): bool
    {
        if ($opportunity->deadline === null || blank($newDeadline)) {
            return false;
        }

        return \Illuminate\Support\Carbon::parse($newDeadline)->lt($opportunity->deadline);
    }

    private static function differs(mixed $current, mixed $incoming): bool
    {
        if ($current instanceof \DateTimeInterface) {
            return blank($incoming)
                || \Illuminate\Support\Carbon::parse($incoming)->notEqualTo($current);
        }

        // Loose on purpose: form input arrives as strings, so "12" replacing 12
        // is not a change anyone made.
        return (string) ($current ?? '') !== (string) ($incoming ?? '');
    }

    private static function normalisePublication(?string $status): string
    {
        $upper = strtoupper(trim((string) $status));

        return in_array($upper, OpportunityStatus::ALL, true) ? $upper : OpportunityStatus::ACTIVE;
    }

    private static function normaliseModeration(?string $status): string
    {
        $upper = strtoupper(trim((string) $status));

        return array_key_exists($upper, self::MODERATION_TRANSITIONS)
            ? $upper
            : OpportunityModerationStatus::PENDING;
    }
}
