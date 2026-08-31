<?php

namespace App\Support;

/**
 * Where a listing sits in its own publication lifecycle, independent of what an
 * administrator has decided about it.
 *
 * WITHDRAWN belongs here rather than in OpportunityModerationStatus, where it
 * used to live. Withdrawing is something a provider does to their own listing;
 * approving and declining are things an administrator does to it. Storing both
 * in one column meant a provider withdrawing an approved listing overwrote the
 * approval, so the platform could no longer tell whether that listing had ever
 * passed review - and nothing could ever put it back, because the verdict it
 * would have been restored to had been erased by the act of hiding it.
 */
final class OpportunityStatus
{
    /** Open, and publishable if moderation and the deadline agree. */
    public const ACTIVE = 'ACTIVE';

    /** Deadline has passed. Reversible by extending the deadline. */
    public const CLOSED = 'CLOSED';

    /** Taken down by the provider. Terminal. */
    public const WITHDRAWN = 'WITHDRAWN';

    public const ALL = [self::ACTIVE, self::CLOSED, self::WITHDRAWN];

    private function __construct()
    {
    }

    public static function displayLabel(?string $status): string
    {
        if (blank($status)) {
            return 'Unknown';
        }

        return match (strtoupper(trim($status))) {
            self::ACTIVE => 'Open',
            self::CLOSED => 'Closed',
            self::WITHDRAWN => 'Withdrawn',
            default => $status,
        };
    }

    public static function badgeTone(?string $status): string
    {
        return match (strtoupper(trim((string) $status))) {
            self::ACTIVE => 'success',
            self::CLOSED => 'warning',
            default => 'secondary',
        };
    }

    public static function isActive(?string $status): bool
    {
        return strcasecmp((string) $status, self::ACTIVE) === 0;
    }

    public static function isWithdrawn(?string $status): bool
    {
        return strcasecmp((string) $status, self::WITHDRAWN) === 0;
    }

    public static function isClosed(?string $status): bool
    {
        return strcasecmp((string) $status, self::CLOSED) === 0;
    }
}
