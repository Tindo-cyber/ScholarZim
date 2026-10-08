<?php

namespace App\Services\ScholarFit;

/**
 * Whether two written fields of study are the same one.
 *
 * One place, so every comparison ScholarFit makes agrees - the structured field a
 * listing states, a field read out of its description, and the check that a
 * description and a structured field contradict each other.
 *
 * For now "the same" means the same field written the same way: case, spacing and
 * "&" versus "and" are ignored. Whether Computer Science and Computer Science & IT
 * are one field is a decision about which fields belong together, and belongs to
 * the field taxonomy, which will replace the body of same() without touching any
 * caller.
 */
final class FieldOfStudyMatcher
{
    private function __construct()
    {
    }

    public static function same(?string $a, ?string $b): bool
    {
        $left = self::normalise($a);
        $right = self::normalise($b);

        return $left !== '' && $left === $right;
    }

    /** Lower-cased, "&" read as "and", every run of whitespace a single space. */
    public static function normalise(?string $value): string
    {
        $clean = strtolower(trim((string) $value));
        $clean = str_replace('&', ' and ', $clean);

        return trim((string) preg_replace('/\s+/', ' ', $clean));
    }
}
