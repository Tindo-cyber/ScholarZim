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
            default => 'secondary',
        };
    }

    public static function isPending(?string $status): bool
    {
        return strcasecmp((string) $status, self::PENDING) === 0;
    }

    public static function isApproved(?string $status): bool
    {
        return strcasecmp((string) $status, self::APPROVED) === 0;
    }
}
