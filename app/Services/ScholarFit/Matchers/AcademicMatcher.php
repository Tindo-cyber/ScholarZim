<?php

namespace App\Services\ScholarFit\Matchers;

use App\Models\Opportunity;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\DimensionResult;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\Academic\AcademicCatalogue;

/**
 * How strong the applicant's results are, graded against the listing's own bar
 * where it sets one.
 *
 * Everything this needs arrives as an argument. The previous version reached
 * the profile through a static property that ScholarFitEngine set immediately
 * before calling it - process-global mutable state, never cleared, leaking
 * between engine instances, on a class whose entire premise is that the same
 * inputs replay to the same score. The AcademicRecord now carries structured
 * results, so there is nothing left to smuggle.
 *
 * Subject scoring reads the RequirementOutcome list the evaluator already
 * produced rather than comparing grades a second time. That is deliberate:
 * two implementations of "does this grade meet that one" is exactly how the
 * score and the explanation drifted apart before, and one of them was always
 * going to be the wrong one.
 *
 * Points mean ZIMSEC A-Level points. No other qualification has a point scale,
 * and none is converted on to this one.
 */
final class AcademicMatcher
{
    /**
     * @param  array<int, RequirementOutcome>  $outcomes  every stated requirement, as evaluated
     */
    public function match(
        AcademicRecord $record,
        Opportunity $opportunity,
        int $weight,
        array $outcomes = [],
    ): DimensionResult {
        $config = config('scholarfit.academic');

        if (! $record->isPresent()) {
            return DimensionResult::make(
                'academic',
                'Academic',
                0.0,
                $weight,
                'No academic results on your profile',
                'Add your subjects and grades to your profile',
                DimensionResult::TARGET_PROFILE,
                'academic-results'
            );
        }

        $subjectOutcomes = array_values(array_filter(
            $outcomes,
            static fn (RequirementOutcome $o) => $o->type === RequirementOutcome::TYPE_SUBJECT,
        ));

        if ($subjectOutcomes !== []) {
            return $this->matchSubjectRequirements($record, $opportunity, $weight, $subjectOutcomes);
        }

        return $this->matchPointsFloor($record, $opportunity, $weight, $config);
    }

    /**
     * @param  array<int, RequirementOutcome>  $subjectOutcomes
     */
    private function matchSubjectRequirements(
        AcademicRecord $record,
        Opportunity $opportunity,
        int $weight,
        array $subjectOutcomes,
    ): DimensionResult {
        $total = count($subjectOutcomes);
        $matched = count(RequirementOutcome::passes($subjectOutcomes));

        $ratio = $total > 0 ? $matched / $total : 0.0;

        $detail = $matched.'/'.$total.' required subjects met';

        $points = $record->pointsFor(AcademicCatalogue::ZIMSEC_A_LEVEL);
        if ($points !== null) {
            $detail .= '; '.AcademicRecord::formatPoints($points).' ZIMSEC A-Level points';
        }

        $missing = RequirementOutcome::failures($subjectOutcomes);

        $fix = $missing === []
            ? null
            : 'Add or update these subjects in your academic results: '
                .implode(', ', array_map(static fn (RequirementOutcome $o) => (string) $o->subject, $missing));

        return DimensionResult::make(
            'academic',
            'Academic',
            $ratio,
            $weight,
            $detail,
            $fix,
            $fix ? DimensionResult::TARGET_PROFILE : null,
            $fix ? 'academic-results' : null,
        );
    }

    private function matchPointsFloor(
        AcademicRecord $record,
        Opportunity $opportunity,
        int $weight,
        array $config,
    ): DimensionResult {
        $floor = $opportunity->min_academic_points;
        $held = $record->pointsFor(AcademicCatalogue::ZIMSEC_A_LEVEL);

        if ($floor !== null && $held !== null) {
            $headroom = max(1, (int) $config['headroom_points']);
            $atFloor = (float) $config['at_floor'];
            $over = $held - $floor;
            $ratio = $atFloor + ((1.0 - $atFloor) * min(1.0, max(0.0, $over) / $headroom));

            return DimensionResult::make(
                'academic',
                'Academic',
                $ratio,
                $weight,
                AcademicRecord::formatPoints($held).' ZIMSEC A-Level points against a '.$floor.'-point requirement'
            );
        }

        if ($floor !== null && $held === null) {
            // The listing asks for A-Level points the applicant has not
            // recorded. The eligibility layer has already failed them for it;
            // scoring it as zero here keeps the two consistent rather than
            // crediting a record that cannot be measured against the bar.
            return DimensionResult::make(
                'academic',
                'Academic',
                0.0,
                $weight,
                'No ZIMSEC A-Level points recorded, and this award asks for '.$floor,
                'Add your ZIMSEC A-Level subjects and grades to your profile',
                DimensionResult::TARGET_PROFILE,
                'academic-results'
            );
        }

        return DimensionResult::make(
            'academic',
            'Academic',
            $record->standaloneStrength(),
            $weight,
            $record->summary()
        );
    }
}
