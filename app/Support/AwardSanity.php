<?php

namespace App\Support;

/**
 * Whether a stated award amount is large enough to deserve a second look.
 *
 * This only answers the question. It does not reject anything: an award above
 * its ceiling is saved as entered and, once moderation flags exist (Phase 3),
 * marked for the reviewer. A hard limit would be wrong in both directions - too
 * low and it blocks a genuine fully funded programme, too high and it misses
 * the typo it exists to catch.
 */
final class AwardSanity
{
    private function __construct()
    {
    }

    public static function ceilingFor(?string $currency): ?float
    {
        $ceilings = (array) config('scholarzim.award_ceilings', []);
        $currency = strtoupper((string) ($currency ?: FormOptions::DEFAULT_CURRENCY));

        return isset($ceilings[$currency]) ? (float) $ceilings[$currency] : null;
    }

    public static function exceedsCeiling(?float $amount, ?string $currency): bool
    {
        $ceiling = self::ceilingFor($currency);

        return $amount !== null && $ceiling !== null && $amount > $ceiling;
    }

    /** The reason a moderator is shown, or null when the amount is within bounds. */
    public static function flagReason(?float $amount, ?string $currency): ?string
    {
        if (! self::exceedsCeiling($amount, $currency)) {
            return null;
        }

        $currency = strtoupper((string) ($currency ?: FormOptions::DEFAULT_CURRENCY));

        return 'Award of ' . number_format((float) $amount) . ' ' . $currency . ' is above the usual ceiling of '
            . number_format((float) self::ceilingFor($currency)) . ' ' . $currency . '. Check it is not a typo.';
    }
}
