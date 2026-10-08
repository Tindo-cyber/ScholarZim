<?php

namespace App\Support;

/**
 * Admin review state of a scholarship post.
 *
 * Deliberately separate from OpportunityStatus, which describes the listing's own
 * lifecycle (open vs. closed). A post can be APPROVED but closed, or ACTIVE but
 * still PENDING review — only the combination of both decides whether the public
 * site shows it.
 */
final class OpportunityModerationStatus
{
    public const PENDING = 'PENDING';
    public const APPROVED = 'APPROVED';
    public const REJECTED = 'REJECTED';

    /**
     * A listing its provider has started and not submitted.
     *
     * Not one of the administrator's verdicts (so it is not in ALL): nobody has been
     * asked to decide anything yet. It can only become PENDING, by being submitted,
     * and nothing ever becomes a draft - see OpportunityLifecycle. Being neither
     * APPROVED nor PENDING, it is public nowhere and in no queue without any of those
     * places having to know it exists; the places that count or list listings
     * without those filters exclude it explicitly (Opportunity::scopeNotDraft).
     */
    public const DRAFT = 'DRAFT';

    /** The three verdicts an administrator can reach, and nothing else. */
    public const ALL = [self::PENDING, self::APPROVED, self::REJECTED];

    private function __construct()
    {
    }

    public static function displayLabel(?string $status): string
    {
        if (blank($status)) {
            return 'Unknown';
        }

        return match (strtoupper(trim($status))) {
            self::PENDING => 'Awaiting review',
            self::APPROVED => 'Published',
            self::REJECTED => 'Declined',
            self::DRAFT => 'Draft',
            default => $status,
        };
    }

    public static function badgeTone(?string $status): string
    {
        if (blank($status)) {
            return 'secondary';
        }

        return match (strtoupper(trim($status))) {
            self::PENDING => 'warning',
            self::APPROVED => 'success',
            self::REJECTED => 'danger',
            self::DRAFT => 'secondary',
            default => 'secondary',
        };
    }

    public static function isPending(?string $status): bool
    {
        return strcasecmp((string) $status, self::PENDING) === 0;
    }

    public static function isDraft(?string $status): bool
    {
        return strcasecmp((string) $status, self::DRAFT) === 0;
    }

    public static function isApproved(?string $status): bool
    {
        return strcasecmp((string) $status, self::APPROVED) === 0;
    }

    public static function isRejected(?string $status): bool
    {
        return strcasecmp((string) $status, self::REJECTED) === 0;
    }
}
