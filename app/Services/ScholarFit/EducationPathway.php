<?php

namespace App\Services\ScholarFit;

use App\Support\EducationLevel;

/**
 * The progressions Zimbabwean education recognises: what usually follows what.
 *
 * This is a description, not a rule. It answers "is this a usual next step?"
 * and nothing more - it does not decide whether an applicant may apply for a
 * scholarship, and `EligibilityEvaluator` reports it as an advisory note
 * rather than a gate.
 *
 * That is a deliberate reversal. This table used to refuse an applicant
 * outright whenever their current level was not listed against a listing's
 * target, which encoded an assumption the product does not want to make: that
 * a level implies its destinations. It does not. An O-Level holder may go on
 * to A-Level, to a polytechnic certificate or diploma, to a college, or
 * straight to some undergraduate programmes, and which of those a particular
 * scholarship is open to is a fact about that scholarship, not about
 * O-Level.
 *
 * So eligibility is decided by what a listing actually states -
 * `minimum_education_level`, `min_academic_points`, its subject requirements,
 * age, province, proof of results - evaluated against the applicant's real
 * qualifications. Where a listing states nothing on a point, nothing is
 * inferred and nothing is refused.
 *
 * What this table is still good for: telling an applicant that a progression
 * is an unusual one, and helping `EducationMatcher` rank an already-eligible
 * field. Both are things worth saying. Neither is a refusal.
 */
final class EducationPathway
{
    /**
     * currentLevel => the levels that usually follow it.
     *
     * Read as "these are the ordinary next steps", not "these are the only
     * ones permitted". A progression absent from this table is unusual, and
     * an applicant is told so; it is not thereby forbidden, because whether a
     * given scholarship accepts them is the scholarship's own requirements to
     * answer.
     *
     * Post-secondary entry is deliberately broad on both O-Level and A-Level:
     * certificate and diploma cover the polytechnic and college routes, and
     * undergraduate covers direct university entry where a programme offers
     * it.
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
     * The sentence describing an unusual progression, or null when it is a
     * recognised one (or unknown, which `isValid()` already treats as valid).
     *
     * Worded as an observation, not a refusal, because that is what it is:
     * the applicant is told their situation is unusual and what the listing
     * actually asks for is reported separately, on its own terms.
     */
    public static function reason(?string $currentLevel, ?string $targetLevel): ?string
    {
        if (self::isValid($currentLevel, $targetLevel)) {
            return null;
        }

        return 'This scholarship funds ' . EducationLevel::label($targetLevel)
            . ' study, which is not a usual next step from ' . EducationLevel::label($currentLevel)
            . '. Check the requirements below before applying.';
    }

    /** The sentence for a recognised progression, for the same advisory line. */
    public static function describe(?string $currentLevel, ?string $targetLevel): string
    {
        return self::reason($currentLevel, $targetLevel)
            ?? 'This scholarship funds ' . EducationLevel::label($targetLevel)
                . ' study, a recognised next step from ' . EducationLevel::label($currentLevel) . '.';
    }
}
