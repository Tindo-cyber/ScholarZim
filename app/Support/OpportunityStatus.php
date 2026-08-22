<?php

namespace App\Support;

final class OpportunityStatus
{
    public const ACTIVE = 'ACTIVE';
    public const CLOSED = 'CLOSED';

    private function __construct()
    {
    }

    public static function displayLabel(?string $status): string
    {
        if (blank($status)) {
            return 'Unknown';
        }

        return strcasecmp($status, self::ACTIVE) === 0 ? 'Open' : $status;
    }

    public static function badgeTone(?string $status): string
    {
        return strcasecmp((string) $status, self::ACTIVE) === 0 ? 'success' : 'secondary';
    }
}
