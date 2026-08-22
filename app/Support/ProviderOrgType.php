<?php

namespace App\Support;

final class ProviderOrgType
{
    public const NGO = 'NGO';
    public const PRIVATE_COMPANY = 'PRIVATE_COMPANY';
    public const FOUNDATION = 'FOUNDATION';
    public const GOVERNMENT = 'GOVERNMENT';
    public const UNIVERSITY = 'UNIVERSITY';
    public const OTHER = 'OTHER';

    public const ALL = [
        self::NGO,
        self::PRIVATE_COMPANY,
        self::FOUNDATION,
        self::GOVERNMENT,
        self::UNIVERSITY,
        self::OTHER,
    ];

    private const LABELS = [
        self::NGO => 'NGO / Non-profit',
        self::PRIVATE_COMPANY => 'Private company',
        self::FOUNDATION => 'Foundation / Trust',
        self::GOVERNMENT => 'Government body',
        self::UNIVERSITY => 'University / College',
        self::OTHER => 'Other registered entity',
    ];

    private function __construct()
    {
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && in_array($value, self::ALL, true);
    }

    public static function label(?string $code): string
    {
        return self::LABELS[$code] ?? (string) $code;
    }

    /** code => label, for building selects. */
    public static function options(): array
    {
        return self::LABELS;
    }
}
