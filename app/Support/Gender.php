<?php

namespace App\Support;

/**
 * The gender values an applicant profile may hold.
 *
 * Captured on the profile and nothing more. ScholarFit does not read it, no
 * eligibility rule tests it, and profile completeness does not require it:
 * this product has no scholarship that states a gender rule, and adding one
 * here would be writing policy rather than implementing it. If a
 * gender-restricted award is ever supported, it needs a column on the
 * opportunity, a check in EligibilityEvaluator and an explanation sentence -
 * a deliberate feature, not a side effect of storing the field.
 */
final class Gender
{
    public const MALE = 'male';

    public const FEMALE = 'female';

    /** @var array<int, string> */
    public const ALL = [self::MALE, self::FEMALE];

    private const LABELS = [
        self::MALE => 'Male',
        self::FEMALE => 'Female',
    ];

    private function __construct()
    {
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && in_array($value, self::ALL, true);
    }

    public static function label(?string $value): ?string
    {
        return self::LABELS[$value] ?? null;
    }

    /** @return array<string, string> value => label, for a radio group or a select. */
    public static function options(): array
    {
        return self::LABELS;
    }
}
