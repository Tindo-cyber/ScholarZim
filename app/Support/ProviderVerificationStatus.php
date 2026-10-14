<?php

namespace App\Support;

use App\Models\ProviderProfile;

/**
 * Where an organisation stands in verification, read from what is already
 * recorded rather than stored a second time.
 *
 *   PENDING         registered (or answered a request), waiting on an administrator
 *   INFO_REQUESTED  an administrator asked for more evidence and is waiting on the organisation
 *   APPROVED        verified; publishing follows the account's status as before
 *   REJECTED        refused, with the reason the organisation was given
 *
 * Every state applies to every organisation alike: the country an organisation
 * is registered in plays no part in it.
 */
final class ProviderVerificationStatus
{
    public const PENDING = 'PENDING';

    public const INFO_REQUESTED = 'INFO_REQUESTED';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    private const LABELS = [
        self::PENDING => 'Awaiting review',
        self::INFO_REQUESTED => 'More information requested',
        self::APPROVED => 'Verified',
        self::REJECTED => 'Rejected',
    ];

    private const TONES = [
        self::PENDING => 'warning',
        self::INFO_REQUESTED => 'info',
        self::APPROVED => 'success',
        self::REJECTED => 'danger',
    ];

    private function __construct()
    {
    }

    public static function of(ProviderProfile $profile): string
    {
        if ($profile->reviewed_at !== null) {
            // A rejected organisation an administrator later reactivated from the
            // users page keeps its old reason on record, but is no longer rejected.
            $status = (string) $profile->user?->account_status;
            $rejected = strcasecmp($status, AccountStatus::REJECTED) === 0
                || ($profile->rejection_reason !== null && strcasecmp($status, AccountStatus::ACTIVE) !== 0);

            return $rejected ? self::REJECTED : self::APPROVED;
        }

        return $profile->isAwaitingInformation() ? self::INFO_REQUESTED : self::PENDING;
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function tone(string $status): string
    {
        return self::TONES[$status] ?? 'secondary';
    }
}
