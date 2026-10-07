<?php

namespace App\Support;

use App\Models\AcademicQualification;
use App\Services\ScholarFit\Taxonomy\EducationLadder;

/**
 * Which listing settings make sense for the level a scholarship targets.
 *
 * The listing form takes each setting on its own, so nothing stopped a provider
 * combining ones that cannot all be true: a PhD award with a Primary-school
 * subject bar, an Undergraduate award whose minimum is a PhD (which excludes
 * every applicant, and passed validation), A-Level points on a Form 1 bursary.
 * These are the rules that connect the settings, kept in one place so the form
 * request, its error messages and the tests all read the same answer.
 *
 * Levels are compared by position on EducationLadder rather than by name, with
 * one addition: FORM_1 exists only as a scholarship target, so the ladder has no
 * rung for it. It is placed between Primary and O Level, which is where an
 * entrant to Form 1 sits. Positions are doubled so that gap exists.
 *
 * Nothing here decides who may apply. These checks run when a provider saves a
 * listing and stop contradictory ones being published; eligibility is still
 * EligibilityEvaluator's.
 */
final class OpportunityLevelRules
{
    /**
     * Targets an A-Level result leads into. The minimum-points rule is ZIMSEC
     * A-Level points only (EligibilityEvaluator::points), so it is only
     * meaningful where an applicant can already hold A-Level results: a
     * Certificate, Diploma or Undergraduate award. Below that the applicant has
     * no A-Level yet; above it they are judged on a degree.
     */
    public const POINTS_TARGETS = [
        EducationLevel::CERTIFICATE,
        EducationLevel::DIPLOMA,
        EducationLevel::UNDERGRADUATE,
    ];

    private const FORM_1_POSITION = 1;

    private function __construct()
    {
    }

    /** A level's place on the ladder, or null for a level nothing recognises. */
    public static function position(?string $level): ?int
    {
        $canonical = EducationLevel::canonical($level);

        if ($canonical === null) {
            return null;
        }

        if ($canonical === EducationLevel::FORM_1) {
            return self::FORM_1_POSITION;
        }

        $rung = EducationLadder::rung($canonical);

        return $rung === null ? null : $rung * 2;
    }

    /**
     * The error for a minimum level above the target, or null when it is fine.
     * Equal is allowed: continuing-student bursaries target a level and
     * require the student to be at it already.
     */
    public static function minimumLevelProblem(?string $minimum, ?string $target): ?string
    {
        $min = self::position($minimum);
        $max = self::position($target);

        if ($min === null || $max === null || $min <= $max) {
            return null;
        }

        return 'The minimum level (' . EducationLevel::label($minimum) . ') is above the level this award targets ('
            . EducationLevel::label($target) . '), so nobody could qualify. Lower the minimum or raise the target.';
    }

    /** The error for a maximum age that rules out everyone at the target level, or null. */
    public static function maxAgeProblem(?int $maxAge, ?string $target): ?string
    {
        $youngest = EducationLevel::minimumAge($target);

        if ($maxAge === null || $youngest === null || $maxAge >= $youngest) {
            return null;
        }

        return 'A maximum age of ' . $maxAge . ' is below the youngest age at which anyone studies at '
            . EducationLevel::label($target) . ' (' . $youngest . '), so nobody could qualify.';
    }

    public static function allowsPoints(?string $target): bool
    {
        $canonical = EducationLevel::canonical($target);

        return $canonical === null || in_array($canonical, self::POINTS_TARGETS, true);
    }

    /** The error for A-Level points on a target they do not apply to, or null. */
    public static function pointsProblem(?int $points, ?string $target): ?string
    {
        if ($points === null || self::allowsPoints($target)) {
            return null;
        }

        return 'A-Level points only apply to Certificate, Diploma and Undergraduate awards. '
            . EducationLevel::label($target) . ' applicants are not judged on A-Level points.';
    }

    /**
     * Whether a results certificate can be asked for at this level: only where
     * the applicant has school results or a transcript to upload.
     */
    public static function allowsResultsCertificate(?string $target): bool
    {
        if (EducationLevel::canonical($target) === null) {
            return true;
        }

        return EducationLevel::usesSchoolResults($target) || EducationLevel::usesTranscript($target);
    }

    public static function resultsCertificateProblem(bool $required, ?string $target): ?string
    {
        if (! $required || self::allowsResultsCertificate($target)) {
            return null;
        }

        return 'A results certificate cannot be required for ' . EducationLevel::label($target)
            . ' awards. Applicants at this level have no school results or transcript to upload.';
    }

    /**
     * Which qualifications a subject requirement may name, by target level.
     *
     * The results an applicant is judged on are the ones they earned on the way
     * into the level, so each target maps to the qualification level or levels
     * that lead into it - not merely "anything lower". "Below the target" alone
     * would let a PhD award require Grade 7 Primary results, which is the
     * contradiction this exists to stop. Honours and above are entered from a
     * degree, so the degree classification is the only school-or-university
     * result that applies there.
     */
    private const QUALIFICATION_LEVELS_BY_TARGET = [
        EducationLevel::FORM_1 => [EducationLevel::PRIMARY],
        EducationLevel::O_LEVEL => [EducationLevel::PRIMARY],
        EducationLevel::A_LEVEL => [EducationLevel::O_LEVEL],
        EducationLevel::CERTIFICATE => [EducationLevel::O_LEVEL, EducationLevel::A_LEVEL],
        EducationLevel::DIPLOMA => [EducationLevel::O_LEVEL, EducationLevel::A_LEVEL],
        EducationLevel::UNDERGRADUATE => [EducationLevel::O_LEVEL, EducationLevel::A_LEVEL],
        EducationLevel::HONOURS => [EducationLevel::UNDERGRADUATE],
        EducationLevel::POSTGRADUATE => [EducationLevel::UNDERGRADUATE],
        EducationLevel::MASTERS => [EducationLevel::UNDERGRADUATE],
        EducationLevel::PHD => [EducationLevel::UNDERGRADUATE],
    ];

    /**
     * Whether a qualification can be required for a target level. A listing with
     * no target, or a qualification the catalogue does not place, is not
     * second-guessed.
     */
    public static function qualificationFits(?string $qualificationLevel, ?string $target): bool
    {
        $goal = EducationLevel::canonical($target);
        $held = EducationLevel::canonical($qualificationLevel);

        if ($goal === null || $held === null) {
            return true;
        }

        return in_array($held, self::QUALIFICATION_LEVELS_BY_TARGET[$goal] ?? [$held], true);
    }
    public static function qualificationProblem(AcademicQualification $qualification, ?string $target): ?string
    {
        if (self::qualificationFits($qualification->education_level, $target)) {
            return null;
        }

        return $qualification->name . ' results cannot be required for a ' . EducationLevel::label($target)
            . ' award: applicants do not hold that qualification when they enter this level.';
    }
}
