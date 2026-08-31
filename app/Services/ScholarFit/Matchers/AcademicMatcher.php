<?php

namespace App\Services\ScholarFit\Matchers;

use App\Models\Opportunity;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\DimensionResult;

/**
 * How strong the applicant's results are - graded against the listing's own bar
 * where it sets one.
 *
 * v1 scored this as a boolean: a heuristic decided whether the record "looked
 * competitive" and awarded the whole 20 points or none of them. That made a
 * student with 14 points and a student who wrote "passed" indistinguishable,
 * and it ignored the listing entirely, so the same record scored identically
 * against an award demanding 15 points and one demanding none.
 */
final class AcademicMatcher
{
    public function match(AcademicRecord $record, Opportunity $opportunity, int $weight): DimensionResult
    {
        $config = config('scholarfit.academic');

        if (! $record->isPresent) {
            return DimensionResult::make(
                'academic',
                'Academic',
                0.0,
                $weight,
                'No academic results on your profile',
                'Add O/A-Level points, subject grades, or degree class to your profile',
                DimensionResult::TARGET_PROFILE,
                'academic_results'
            );
        }

        $floor = $opportunity->min_academic_points;

        // With a floor stated, the marks are earned against it. Anyone still
        // here has already cleared it - Layer 1 removed those who did not - so
        // this grades the margin rather than re-testing the rule.
        if ($floor !== null && $record->hasComparablePoints()) {
            $headroom = max(1, (int) $config['headroom_points']);
            $atFloor = (float) $config['at_floor'];
            $over = $record->points - $floor;
            $ratio = $atFloor + ((1.0 - $atFloor) * min(1.0, $over / $headroom));

            return DimensionResult::make(
                'academic',
                'Academic',
                $ratio,
                $weight,
                $record->points . ' points against a ' . $floor . '-point requirement'
            );
        }

        // No floor to measure against, so the record is judged on its own terms.
        return DimensionResult::make(
            'academic',
            'Academic',
            $record->standaloneStrength(),
            $weight,
            $record->summary()
        );
    }
}
