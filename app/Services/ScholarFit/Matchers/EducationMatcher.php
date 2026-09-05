<?php

namespace App\Services\ScholarFit\Matchers;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\DimensionResult;
use App\Services\ScholarFit\Taxonomy\EducationLadder;

/**
 * How close the applicant's education level is to the one the listing targets
 * - a *ranking* question, asked only once eligibility has already said yes.
 *
 * This class does not decide whether an applicant may be considered for a
 * listing; `EducationPathway` and `EligibilityEvaluator` do that, before this
 * runs, using an explicit table rather than distance. What is left for this
 * class is ranking the applicants who are already eligible: two rungs apart
 * still earns something because a diploma holder applying to an undergraduate
 * award is a real if imperfect candidate among other eligible candidates,
 * while an exact match plainly ranks above a near one. Distance is a
 * reasonable way to answer that question and a bad way to answer the
 * eligibility question, which is the whole reason the two are separate
 * classes now.
 */
final class EducationMatcher
{
    public function match(ApplicantProfile $profile, Opportunity $opportunity, int $weight): DimensionResult
    {
        $credit = config('scholarfit.credit');
        $profileLevel = $profile->education_level;
        $targetLevel = $opportunity->education_level;

        if (blank($profileLevel)) {
            return DimensionResult::make(
                'education',
                'Education',
                0.0,
                $weight,
                'No education level on your profile',
                'Complete your education level on your profile',
                DimensionResult::TARGET_PROFILE,
                'education_level'
            );
        }

        // The listing names no level. Unknown, so a half mark - not the 60% v1
        // handed out, and emphatically not a match.
        if (blank($targetLevel)) {
            return DimensionResult::make(
                'education',
                'Education',
                (float) $credit['neutral'],
                $weight,
                'This listing does not state an education level'
            );
        }

        $distance = EducationLadder::distance($profileLevel, $targetLevel);

        // One or both spellings are off the ladder entirely, so fall back to
        // comparing them as text rather than inventing a distance.
        if ($distance === null) {
            $same = strcasecmp(trim($profileLevel), trim($targetLevel)) === 0;

            return DimensionResult::make(
                'education',
                'Education',
                $same ? 1.0 : 0.0,
                $weight,
                $same
                    ? 'Exact match: ' . $targetLevel
                    : 'Requires ' . $targetLevel . '; your profile shows ' . $profileLevel,
                $same ? null : 'Requires ' . $targetLevel . ' - your profile shows ' . $profileLevel,
                $same ? null : DimensionResult::TARGET_PROFILE,
                $same ? null : 'education_level'
            );
        }

        return match (true) {
            $distance === 0 => DimensionResult::make(
                'education',
                'Education',
                1.0,
                $weight,
                'Exact match: ' . $targetLevel
            ),
            $distance === 1 => DimensionResult::make(
                'education',
                'Education',
                (float) $credit['related'],
                $weight,
                'Adjacent level: ' . $profileLevel . ' against ' . $targetLevel
            ),
            $distance === 2 => DimensionResult::make(
                'education',
                'Education',
                (float) $credit['distant'],
                $weight,
                'Two levels apart: ' . $profileLevel . ' against ' . $targetLevel
            ),
            default => DimensionResult::make(
                'education',
                'Education',
                0.0,
                $weight,
                'Requires ' . $targetLevel . '; your profile shows ' . $profileLevel,
                'Requires ' . $targetLevel . ' - your profile shows ' . $profileLevel,
                DimensionResult::TARGET_PROFILE,
                'education_level'
            ),
        };
    }
}
