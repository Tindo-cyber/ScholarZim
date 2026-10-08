<?php

namespace App\Services\ScholarFit;

use App\Support\EducationLevel;

/**
 * Whether the level a listing is for is reachable from the level an applicant is at.
 *
 * Three answers, and only one of them refuses anybody:
 *
 *   FAIL        impossible: a Grade 7 pupil cannot start a diploma, an O-Level
 *               student cannot hold a PhD, a Master's graduate is not a Form 1
 *               pupil. Shown as a failed requirement.
 *   RECOGNISED  a usual next step (EducationPathway says so).
 *   UNUSUAL     neither impossible nor usual - going back a rung within tertiary,
 *               an Undergraduate for a Certificate. Reported as a note and left
 *               to the listing's own stated requirements to accept or refuse.
 *
 * The line for FAIL is deliberately narrow. EducationPathway used to refuse
 * anything off its table of usual steps, which made a level imply its destinations
 * and turned away people no listing had turned away. Only a jump no one actually
 * makes is a failure here:
 *
 *   - a Form 1 award is a Grade 7 transition bursary: only a Primary pupil is in it;
 *   - a Primary pupil can enter Form 1 and O-Level and nothing above;
 *   - a secondary student cannot hold a postgraduate award (Postgraduate, Masters,
 *     PhD);
 *   - a Certificate or Diploma student cannot hold a postgraduate award either: those
 *     are entered from a degree, so it is no more reachable than for a school student;
 *   - anyone at tertiary level or above is not looking for an O-Level or A-Level
 *     award. Rare enough that the provider can handle it, and better than showing
 *     it as a match.
 *
 * What stays a note: an Undergraduate for a PhD (a degree holder going straight to doctoral
 * study happens), and a Masters student for an undergraduate award.
 *
 * HONOURS is not a level here. A Zimbabwean BSc / BCom Honours is a bachelor's degree
 * (Undergraduate) and the one-year South African honours is Postgraduate; the word is
 * only a legacy alias that EducationLevel::canonical() reads as Undergraduate.
 *
 * This only answers the level question. Whether the listing then accepts the person
 * is decided by the requirements it states.
 */
final class LevelJump
{
    public const FAIL = 'fail';

    public const RECOGNISED = 'recognised';

    public const UNUSUAL = 'unusual';

    private function __construct()
    {
    }

    /** The verdict, or null when either level is blank or unrecognised - unknown is not a failure. */
    public static function verdict(?string $applicantLevel, ?string $targetLevel): ?string
    {
        $applicant = EducationLevel::canonical($applicantLevel);
        $target = EducationLevel::canonical($targetLevel);

        if ($applicant === null || $target === null) {
            return null;
        }

        if (self::impossible($applicant, $target)) {
            return self::FAIL;
        }

        return EducationPathway::isValid($applicant, $target) ? self::RECOGNISED : self::UNUSUAL;
    }

    private static function impossible(string $applicant, string $target): bool
    {
        // Only a Primary pupil moving up is in a Form 1 transition award.
        if ($target === EducationLevel::FORM_1) {
            return $applicant !== EducationLevel::PRIMARY;
        }

        // A Grade 7 pupil can start Form 1 (which is the first year of O-Level) and nothing above it.
        if ($applicant === EducationLevel::PRIMARY) {
            return $target !== EducationLevel::O_LEVEL;
        }

        // Certificate and Diploma holders have not got the degree a postgraduate award is entered from.
        if (in_array($applicant, [EducationLevel::CERTIFICATE, EducationLevel::DIPLOMA], true)
            && in_array($target, [EducationLevel::POSTGRADUATE, EducationLevel::MASTERS, EducationLevel::PHD], true)) {
            return true;
        }

        if (EducationLevel::tier($applicant) === EducationLevel::TIER_SECONDARY) {
            return in_array($target, [EducationLevel::POSTGRADUATE, EducationLevel::MASTERS, EducationLevel::PHD], true);
        }

        // Tertiary and postgraduate applicants against school-level awards.
        return in_array($target, [EducationLevel::O_LEVEL, EducationLevel::A_LEVEL], true);
    }

    /** The sentence for a failed jump, naming both levels and which way the gap runs. */
    public static function failureMessage(?string $applicantLevel, ?string $targetLevel): string
    {
        $target = EducationLevel::canonical($targetLevel);
        $held = EducationLevel::label($applicantLevel);

        if ($target === EducationLevel::FORM_1) {
            return 'Level: this is a Form 1 transition award for Primary school leavers, and your profile states ' . $held . '.';
        }

        $below = (\App\Support\OpportunityLevelRules::position($applicantLevel) ?? 0)
            < (\App\Support\OpportunityLevelRules::position($targetLevel) ?? 0);

        return 'Level: this scholarship funds ' . EducationLevel::label($targetLevel) . ' study, but your profile states '
            . $held . ', which is ' . ($below ? 'below it - it cannot lead straight into it' : 'beyond it - you have already passed it') . '.';
    }
}
