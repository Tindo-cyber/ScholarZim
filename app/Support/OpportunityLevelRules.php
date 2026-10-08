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
     * Whether proof of results can be asked for at this level. Every level can: an O/A-Level results certificate,
     * a tertiary or postgraduate transcript, and - for a Form 1 award - a Grade 7 results slip, which a Primary
     * pupil can upload. Only the one with no level chosen is open to anything, so it too is allowed.
     */
    public static function allowsResultsCertificate(?string $target): bool
    {
        $canonical = EducationLevel::canonical($target);

        return $canonical === null
            || $canonical === EducationLevel::FORM_1
            || EducationLevel::usesSchoolResults($target)
            || EducationLevel::usesTranscript($target);
    }

    public static function resultsCertificateProblem(bool $required, ?string $target): ?string
    {
        if (! $required || self::allowsResultsCertificate($target)) {
            return null;
        }

        return 'Proof of results cannot be required for ' . EducationLevel::label($target)
            . ' awards. Applicants at this level have nothing to upload.';
    }

    /**
     * Which qualifications a subject requirement may name, by target level.
     *
     * The results an applicant is judged on are the ones they earned on the way
     * into the level, so each target maps to the qualification level or levels
     * that lead into it - not merely "anything lower". "Below the target" alone
     * would let a PhD award require Grade 7 Primary results, which is the
     * contradiction this exists to stop. Postgraduate and above are entered from a
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

    /**
     * Whether a subject requirement can be set at all for this target: some
     * qualification that holds subjects must lead into it. Postgraduate and above are
     * entered from a degree, and the degree classification carries no subjects, so
     * there is nothing a subject row could name.
     */
    public static function allowsSubjectRequirements(?string $target): bool
    {
        $goal = EducationLevel::canonical($target);

        if ($goal === null) {
            return true;
        }

        return array_diff(self::QUALIFICATION_LEVELS_BY_TARGET[$goal] ?? [], [EducationLevel::UNDERGRADUATE]) !== [];
    }

    /**
     * Which parts of the listing form a target level uses.
     *
     * One answer, read by the page (as JSON, for the script that hides what does
     * not apply) and by the server (to clear what a hidden field left behind), so
     * the two cannot drift: a field the form tidies away is exactly a field the
     * server would refuse or ignore.
     *
     *   field       field of study is a meaningful concept at this level
     *   points      minimum ZIMSEC A-Level points can apply
     *   certificate "proof of results on file" can be asked of an applicant here
     *   subjects    a required subject can be named
     *   programmes  the award can be narrowed to programmes, fields or institutions
     *
     * No target ("Any level") uses everything: nothing is known to be irrelevant.
     *
     * @return array{field: bool, points: bool, certificate: bool, subjects: bool, programmes: bool}
     */
    public static function capabilities(?string $target): array
    {
        $known = EducationLevel::canonical($target) !== null;

        return [
            'field' => ! $known || EducationLevel::usesFieldOfStudy($target),
            'points' => self::allowsPoints($target),
            'certificate' => self::allowsResultsCertificate($target),
            'subjects' => self::allowsSubjectRequirements($target),
            'programmes' => \App\Services\Catalogue\ListingScopes::appliesTo($target),
        ];
    }

    /**
     * What to blank in a submission because the target level does not use it.
     *
     * Applied only when the form says its script has been tidying the page (see
     * StoreOpportunityRequest). The script clears a field as it hides it, but a
     * stale value can still arrive - a browser that restored old form state, a
     * second tab - and a value for a field nobody can see is an error nobody can
     * fix, so it is dropped instead of reported.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> only the keys to overwrite
     */
    public static function clearInapplicable(array $input, ?string $target): array
    {
        $uses = self::capabilities($target);
        $clear = [];

        if (! $uses['field']) {
            $clear['target_field'] = null;
        }

        if (! $uses['points']) {
            $clear['min_academic_points'] = null;
        }

        if (! $uses['certificate']) {
            $clear['requires_results_certificate'] = false;
        }

        if (! $uses['subjects']) {
            $clear['subject_requirements'] = [];
        }

        if (! $uses['programmes']) {
            $clear['scope_programmes'] = [];
            $clear['scope_fields'] = [];
            $clear['scope_institutions'] = [];
            $clear['programme_suggestion'] = null;
        }

        return $clear;
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
