<?php

namespace App\Services\ScholarFit;

/**
 * The order eligible listings are shown in.
 *
 * Strictly lexicographic: each criterion only breaks ties the one before it left, so a
 * better answer on an earlier criterion can never be outweighed by any number of better
 * answers on later ones. Nothing is added up, nothing is weighted, and no number is
 * produced for anyone to see - EligibilityResult stays free of a score and this class
 * returns an order, not a rating.
 *
 *   1. field of study   the applicant's own programme named by the listing, then a narrow field
 *                       they are in, then a broad field they are in, then everything else (a
 *                       listing open to any field, or limited only by institution). An older
 *                       free-text field setting that matches counts as a narrow field.
 *   2. level step       a usual next step, then a listing that states no level, then an
 *                       unusual step
 *   3. stated rules     listings that state rules the applicant meets, then those that
 *                       state none (a listing that asks nothing says nothing about fit)
 *   4. place            a stated province or town the applicant matches, then the rest
 *   5. deadline         soonest first, no deadline last
 *   6. id               so the order is always the same one
 */
final class MatchOrder
{
    private function __construct()
    {
    }

    /**
     * @param  array<int, EligibilityResult>  $results
     * @return array<int, EligibilityResult> a new, ordered list; the input is left alone
     */
    public static function sort(array $results): array
    {
        usort($results, static fn (EligibilityResult $a, EligibilityResult $b) => self::key($a) <=> self::key($b));

        return array_values($results);
    }

    /**
     * What the comparison looks at, in order. Internal: an array of small integers whose
     * only meaning is how it compares with another one.
     *
     * @return array<int, int>
     */
    public static function key(EligibilityResult $result): array
    {
        $outcomes = $result->outcomes;

        return [
            self::fieldTier($outcomes),
            self::levelTier($outcomes),
            $result->hasStatedRequirements() ? 0 : 1,
            self::placeTier($outcomes),
            $result->opportunity->deadline?->timestamp ?? PHP_INT_MAX,
            (int) $result->opportunity->opportunity_id,
        ];
    }

    /** @param  array<int, RequirementOutcome>  $outcomes */
    private static function fieldTier(array $outcomes): int
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->type === RequirementOutcome::TYPE_PROGRAMME_SCOPE && ! $outcome->advisory && $outcome->passed) {
                return match ($outcome->fit) {
                    RequirementOutcome::FIT_PROGRAMME => 0,
                    RequirementOutcome::FIT_NARROW_FIELD => 1,
                    RequirementOutcome::FIT_BROAD_FIELD => 2,
                    default => 3,
                };
            }
        }

        // The older, free-text field of study: a match is as good as a narrow field.
        foreach ($outcomes as $outcome) {
            $isField = in_array($outcome->type, [RequirementOutcome::TYPE_FIELD, RequirementOutcome::TYPE_DESCRIPTION_FIELD], true);

            if ($isField && ! $outcome->advisory && $outcome->passed
                && FieldOfStudyMatcher::relation((string) $outcome->actual, (string) $outcome->required) === FieldOfStudyMatcher::EXACT) {
                return 1;
            }
        }

        return 3;
    }

    /** @param  array<int, RequirementOutcome>  $outcomes */
    private static function levelTier(array $outcomes): int
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->type === RequirementOutcome::TYPE_PROGRESSION && $outcome->advisory) {
                return $outcome->passed ? 0 : 2;
            }
        }

        return 1;
    }

    /** @param  array<int, RequirementOutcome>  $outcomes */
    private static function placeTier(array $outcomes): int
    {
        foreach ($outcomes as $outcome) {
            $isPlace = in_array($outcome->type, [RequirementOutcome::TYPE_PROVINCE, RequirementOutcome::TYPE_LOCALITY], true);

            if ($isPlace && ! $outcome->advisory && $outcome->passed) {
                return 0;
            }
        }

        return 1;
    }
}
