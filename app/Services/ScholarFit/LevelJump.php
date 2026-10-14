<?php

namespace App\Services\ScholarFit;

use App\Services\ScholarFit\Taxonomy\EducationLadder;
use App\Support\EducationLevel;

/**
 * Whether the level a listing is for is the next stage for the level an applicant has finished.
 *
 * The level on a profile is the highest level the applicant has COMPLETED, and a scholarship
 * is for the next stage of study. So an applicant is refused (FAIL) for:
 *
 *   - the level they have already finished, or anything below it: an O-Level leaver is not
 *     looking for O-Level funding, a Diploma holder is not looking for a Certificate;
 *   - a Form 1 award unless they have finished Primary (it is a Grade 7 transition bursary);
 *   - anything beyond O-Level for someone who has finished Primary;
 *   - a postgraduate award (Postgraduate, Masters, PhD) without a degree;
 *   - a PhD without a Master's - a degree or postgraduate diploma alone is not the way in.
 *
 * Everything else is a RECOGNISED next step. O-Level straight to a degree is one, but
 * EligibilityEvaluator refuses it unless the provider has said the award accepts O-Level
 * holders. UNUSUAL is kept for levels EducationPathway does not list, which the rules above
 * leave none of.
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

        // Never the level already finished, and never below it: the scholarship is for the next stage.
        if (EducationLadder::rung($target) <= EducationLadder::rung($applicant)) {
            return true;
        }

        // A PhD is entered from a Master's.
        if ($target === EducationLevel::PHD) {
            return $applicant !== EducationLevel::MASTERS;
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

        $funds = 'Level: this scholarship funds ' . EducationLevel::label($targetLevel) . ' study, but ';

        if (EducationLadder::rung($targetLevel) === EducationLadder::rung($applicantLevel)) {
            return $funds . 'your profile says you have already finished ' . $held . '. Scholarships here are for the next stage of study.';
        }

        if ($target === EducationLevel::PHD && EducationLadder::rung($applicantLevel) < EducationLadder::rung(EducationLevel::MASTERS)) {
            return $funds . 'a PhD is entered from a Master\'s degree, and your profile states ' . $held . '.';
        }

        $below = (\App\Support\OpportunityLevelRules::position($applicantLevel) ?? 0)
            < (\App\Support\OpportunityLevelRules::position($targetLevel) ?? 0);

        return $funds . 'your profile states '
            . $held . ', which is ' . ($below ? 'below it - it cannot lead straight into it' : 'beyond it - you have already passed it') . '.';
    }
}
