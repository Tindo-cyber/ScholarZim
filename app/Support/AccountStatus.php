<?php

namespace App\Support;

final class AccountStatus
{
    public const ACTIVE = 'ACTIVE';
    public const PENDING = 'PENDING';
    public const SUSPENDED = 'SUSPENDED';
    public const REJECTED = 'REJECTED';

    private function __construct()
    {
    }

    public static function isSuspended(?string $status): bool
    {
        return strcasecmp((string) $status, self::SUSPENDED) === 0;
    }

    public static function displayLabel(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            self::ACTIVE => 'Active',
            self::PENDING => 'Pending approval',
            self::SUSPENDED => 'Suspended',
            self::REJECTED => 'Rejected',
            default => 'Unknown',
        };
    }

    public static function badgeTone(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            self::ACTIVE => 'success',
            self::PENDING => 'warning',
            self::SUSPENDED => 'danger',
            self::REJECTED => 'danger',
            default => 'secondary',
        };
    }
}
