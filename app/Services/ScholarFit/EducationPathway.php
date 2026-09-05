<?php

namespace App\Services\ScholarFit;

use App\Support\EducationLevel;

/**
 * Whether an applicant's current education level can reach a scholarship's
 * target level at all - a hard yes/no, never a percentage.
 *
 * This exists because `EducationMatcher` (the scoring dimension) and this
 * class answer two different questions that `EducationLadder::distance()`
 * used to be asked to answer at once. Distance is a reasonable way to rank
 * *already-eligible* applicants against each other - two rungs apart is a
 * weaker candidate than an exact match - but it is a bad way to decide
 * eligibility, because "two rungs apart" describes both a diploma holder
 * eyeing an undergraduate award (plausible) and a primary pupil eyeing a PhD
 * scholarship (not a real scenario, and previously scored as a distant-but-
 * nonzero match rather than refused outright).
 *
 * The table below is deliberately an explicit adjacency list, not a computed
 * range on the ladder, precisely so a case like "O Level to Masters" cannot
 * be accidentally rescued by an off-by-one in a distance calculation. Every
 * entry is a pathway that genuinely exists in Zimbabwean education, and
 * nothing is inferred.
 *
 * A scholarship's target level establishes the pathway is *possible*, not
 * that this specific scholarship accepts every level that could reach it -
 * that is what `Opportunity::minimum_education_level` is for, checked
 * separately in `EligibilityEvaluator`. This class only ever answers "could a
 * student at this level ever sensibly apply for a scholarship aimed at that
 * level", the same question for every scholarship with that target.
 */
final class EducationPathway
{
    /**
     * currentLevel => the target levels a scholarship may sensibly be aimed at.
     *
     * Same-tier and forward moves only; nothing moves backward (an
     * undergraduate is not "eligible" for a Form 1 scholarship) and nothing
     * skips more than one tier (a Primary pupil cannot reach Undergraduate in
     * one step, regardless of how good their results are - that is not a
     * scoring judgement, it is how the education system is structured).
     */
    private const VALID_TARGETS = [
        EducationLevel::PRIMARY => [
            EducationLevel::FORM_1,
        ],
        EducationLevel::O_LEVEL => [
            EducationLevel::O_LEVEL,
            EducationLevel::A_LEVEL,
            EducationLevel::CERTIFICATE,
            EducationLevel::DIPLOMA,
            EducationLevel::UNDERGRADUATE,
        ],
        EducationLevel::A_LEVEL => [
            EducationLevel::A_LEVEL,
            EducationLevel::CERTIFICATE,
            EducationLevel::DIPLOMA,
            EducationLevel::UNDERGRADUATE,
        ],
        EducationLevel::CERTIFICATE => [
            EducationLevel::CERTIFICATE,
            EducationLevel::DIPLOMA,
            EducationLevel::UNDERGRADUATE,
        ],
        EducationLevel::DIPLOMA => [
            EducationLevel::DIPLOMA,
            EducationLevel::UNDERGRADUATE,
            EducationLevel::HONOURS,
        ],
        EducationLevel::UNDERGRADUATE => [
            EducationLevel::UNDERGRADUATE,
            EducationLevel::HONOURS,
            EducationLevel::POSTGRADUATE,
            EducationLevel::MASTERS,
        ],
        EducationLevel::HONOURS => [
            EducationLevel::HONOURS,
            EducationLevel::POSTGRADUATE,
            EducationLevel::MASTERS,
        ],
        EducationLevel::POSTGRADUATE => [
            EducationLevel::POSTGRADUATE,
            EducationLevel::MASTERS,
            EducationLevel::PHD,
        ],
        EducationLevel::MASTERS => [
            EducationLevel::MASTERS,
            EducationLevel::PHD,
        ],
        EducationLevel::PHD => [
            EducationLevel::PHD,
        ],
    ];

    private function __construct()
    {
    }

    /**
     * True when either level is blank or unrecognised - consistent with the
     * rest of ScholarFit's eligibility checks, an unstated or unreadable value
     * is treated as unknown and does not block anything. It is also true
     * whenever the table above says the transition is real.
     */
    public static function isValid(?string $currentLevel, ?string $targetLevel): bool
    {
        $current = EducationLevel::canonical($currentLevel);
        $target = EducationLevel::canonical($targetLevel);

        if ($current === null || $target === null) {
            return true;
        }

        return in_array($target, self::VALID_TARGETS[$current] ?? [], true);
    }

    /**
     * The sentence explaining a failed pathway, or null when the pathway is
     * valid (or unknown, which `isValid()` already treats as valid).
     */
    public static function reason(?string $currentLevel, ?string $targetLevel): ?string
    {
        if (self::isValid($currentLevel, $targetLevel)) {
            return null;
        }

        return 'This scholarship is for ' . EducationLevel::label($targetLevel)
            . ' applicants. Your current education level is ' . EducationLevel::label($currentLevel) . '.';
    }
}
