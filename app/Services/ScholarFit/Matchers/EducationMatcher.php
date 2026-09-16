<?php

namespace App\Services\ScholarFit\Matchers;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\DimensionResult;
use App\Services\ScholarFit\EducationPathway;
use App\Services\ScholarFit\Taxonomy\EducationLadder;

/**
 * How well the applicant's education level fits the one the listing targets -
 * a ranking question, never a refusal.
 *
 * Eligibility is decided by what the listing explicitly requires, in
 * `EligibilityEvaluator`. This class carries the rest of the judgement, and
 * carries more of it than it used to: with the progression table no longer
 * refusing anyone, ranking is what keeps an unusual step below an ordinary
 * one instead of hiding it.
 *
 * Two things feed the score. Distance on the ladder ranks near misses against
 * exact ones. And a progression EducationPathway recognises earns a floor
 * regardless of distance, because rung-counting alone reads O-Level to a
 * polytechnic diploma as three steps of nothing when it is in fact one of the
 * ordinary routes out of O-Level. Without that floor a legitimate candidate
 * would rank level with an implausible one, which is the recommending half of
 * the same mistake refusing them used to be.
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
        // comparing them as text rather than inventing a distance. Form 1 is
        // the case that matters here: it is a scholarship target rather than a
        // rung anyone stands on, so Primary towards Form 1 has no distance at
        // all - and it is the most ordinary progression in the product.
        if ($distance === null) {
            $same = strcasecmp(trim($profileLevel), trim($targetLevel)) === 0;

            if ($same) {
                return DimensionResult::make(
                    'education',
                    'Education',
                    1.0,
                    $weight,
                    'Exact match: ' . $targetLevel
                );
            }

            if (EducationPathway::isValid($profileLevel, $targetLevel)) {
                return DimensionResult::make(
                    'education',
                    'Education',
                    (float) $credit['related'],
                    $weight,
                    'Recognised next step: ' . $profileLevel . ' towards ' . $targetLevel
                );
            }

            return DimensionResult::make(
                'education',
                'Education',
                0.0,
                $weight,
                'Requires ' . $targetLevel . '; your profile shows ' . $profileLevel,
                'Requires ' . $targetLevel . ' - your profile shows ' . $profileLevel,
                DimensionResult::TARGET_PROFILE,
                'education_level'
            );
        }

        if ($distance === 0) {
            return DimensionResult::make(
                'education',
                'Education',
                1.0,
                $weight,
                'Exact match: ' . $targetLevel
            );
        }

        // A step the education system recognises is a real candidacy however
        // many rungs separate the two levels, so it never scores below a near
        // miss. O-Level to a polytechnic diploma is three rungs and entirely
        // ordinary; O-Level to a PhD is further still and is not.
        $recognised = EducationPathway::isValid($profileLevel, $targetLevel);

        $ratio = match (true) {
            $distance === 1 => (float) $credit['related'],
            $distance === 2 => (float) $credit['distant'],
            default => 0.0,
        };

        if ($recognised) {
            $ratio = max($ratio, (float) $credit['related']);
        }

        if ($ratio <= 0.0) {
            return DimensionResult::make(
                'education',
                'Education',
                0.0,
                $weight,
                'Requires ' . $targetLevel . '; your profile shows ' . $profileLevel,
                'Requires ' . $targetLevel . ' - your profile shows ' . $profileLevel,
                DimensionResult::TARGET_PROFILE,
                'education_level'
            );
        }

        return DimensionResult::make(
            'education',
            'Education',
            $ratio,
            $weight,
            $recognised
                ? 'Recognised next step: ' . $profileLevel . ' towards ' . $targetLevel
                : ($distance === 1
                    ? 'Adjacent level: ' . $profileLevel . ' against ' . $targetLevel
                    : 'Two levels apart: ' . $profileLevel . ' against ' . $targetLevel)
        );
    }
}
