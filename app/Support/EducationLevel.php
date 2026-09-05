<?php

namespace App\Support;

/**
 * The canonical education levels ScholarZim reasons about, and the legacy
 * spellings that map onto each one.
 *
 * Before this existed, "current education level" was whatever string a
 * profile dropdown happened to offer (`Primary — Grade 4`, `Secondary — Form
 * 6`, `High School (A-Level)`, ...) and every consumer - EducationLadder,
 * FieldMatcher, the completion checklist - had to know those spellings
 * independently. `EducationLadder::RUNGS` already collapsed most of that into
 * ten ordered rungs for *scoring*; this class gives the same ten rungs a name
 * each can be addressed by for *everything else* - eligibility, form
 * rendering, validation - and is the one place a new spelling gets taught to
 * the system.
 *
 * FORM_1 is an eleventh value that exists only as a scholarship *target*: a
 * scholarship "for Form 1 entrants" is a transition opportunity for a Primary
 * pupil, not a level any applicant's own profile is ever set to. No provider
 * form or profile form should offer it as anything other than a target.
 *
 * Existing `education_level` column values are never rewritten by this class.
 * `canonical()` is read-time mapping, the same pattern `ApplicationStatus`
 * already uses for its own legacy statuses - a historical row keeps whatever
 * string it has, and every current or future consumer reads it correctly
 * regardless.
 */
final class EducationLevel
{
    public const PRIMARY = 'PRIMARY';

    /** Scholarship-target only - see class docblock. */
    public const FORM_1 = 'FORM_1';

    public const O_LEVEL = 'O_LEVEL';

    public const A_LEVEL = 'A_LEVEL';

    public const CERTIFICATE = 'CERTIFICATE';

    public const DIPLOMA = 'DIPLOMA';

    public const UNDERGRADUATE = 'UNDERGRADUATE';

    public const HONOURS = 'HONOURS';

    public const POSTGRADUATE = 'POSTGRADUATE';

    public const MASTERS = 'MASTERS';

    public const PHD = 'PHD';

    /** Every value an applicant's own profile may hold. FORM_1 is deliberately absent. */
    public const APPLICANT_LEVELS = [
        self::PRIMARY,
        self::O_LEVEL,
        self::A_LEVEL,
        self::CERTIFICATE,
        self::DIPLOMA,
        self::UNDERGRADUATE,
        self::HONOURS,
        self::POSTGRADUATE,
        self::MASTERS,
        self::PHD,
    ];

    /** Every value a scholarship may target, including the entry-only FORM_1. */
    public const TARGET_LEVELS = [
        self::FORM_1,
        self::O_LEVEL,
        self::A_LEVEL,
        self::CERTIFICATE,
        self::DIPLOMA,
        self::UNDERGRADUATE,
        self::HONOURS,
        self::POSTGRADUATE,
        self::MASTERS,
        self::PHD,
    ];

    public const TIER_PRIMARY = 'PRIMARY';

    public const TIER_SECONDARY = 'SECONDARY';

    public const TIER_TERTIARY = 'TERTIARY';

    public const TIER_POSTGRADUATE = 'POSTGRADUATE';

    private const TIERS = [
        self::PRIMARY => self::TIER_PRIMARY,
        self::FORM_1 => self::TIER_SECONDARY,
        self::O_LEVEL => self::TIER_SECONDARY,
        self::A_LEVEL => self::TIER_SECONDARY,
        self::CERTIFICATE => self::TIER_TERTIARY,
        self::DIPLOMA => self::TIER_TERTIARY,
        self::UNDERGRADUATE => self::TIER_TERTIARY,
        self::HONOURS => self::TIER_POSTGRADUATE,
        self::POSTGRADUATE => self::TIER_POSTGRADUATE,
        self::MASTERS => self::TIER_POSTGRADUATE,
        self::PHD => self::TIER_POSTGRADUATE,
    ];

    private const LABELS = [
        self::PRIMARY => 'Primary',
        self::FORM_1 => 'Form 1 (secondary entry)',
        self::O_LEVEL => 'O Level',
        self::A_LEVEL => 'A Level',
        self::CERTIFICATE => 'Certificate',
        self::DIPLOMA => 'Diploma',
        self::UNDERGRADUATE => 'Undergraduate',
        self::HONOURS => 'Honours Degree',
        self::POSTGRADUATE => 'Postgraduate',
        self::MASTERS => 'Masters',
        self::PHD => 'PhD',
    ];

    /**
     * Legacy spelling => canonical value. Keys are matched case-insensitively
     * with punctuation folded to spaces, the same normalisation
     * `EducationLadder` already applies, so this reads every spelling that has
     * ever been stored, including the granular grade/form values the
     * PRIMARY_GRADES and SECONDARY_FORMS dropdowns used to offer.
     */
    private const LEGACY_MAP = [
        // Primary — every individual grade collapses to one tier.
        'primary' => self::PRIMARY,
        'general primary' => self::PRIMARY,
        'primary grade 1' => self::PRIMARY,
        'primary grade 2' => self::PRIMARY,
        'primary grade 3' => self::PRIMARY,
        'primary grade 4' => self::PRIMARY,
        'primary grade 5' => self::PRIMARY,
        'primary grade 6' => self::PRIMARY,
        'primary grade 7' => self::PRIMARY,

        // Secondary forms. Form 1-3 have historically been stored as
        // "Secondary" with no O/A split; ScholarZim has never distinguished a
        // form-1 *applicant* from an O-Level one; that distinction exists only
        // for a scholarship's target (FORM_1 above).
        'secondary form 1' => self::O_LEVEL,
        'secondary form 2' => self::O_LEVEL,
        'secondary form 3' => self::O_LEVEL,
        'secondary form 4' => self::O_LEVEL,
        'secondary form 5' => self::A_LEVEL,
        'secondary form 6' => self::A_LEVEL,
        'secondary' => self::O_LEVEL,
        'form 1' => self::FORM_1,

        'high school o level' => self::O_LEVEL,
        'o level' => self::O_LEVEL,
        'olevel' => self::O_LEVEL,
        'ordinary level' => self::O_LEVEL,
        'form 4' => self::O_LEVEL,

        'high school a level' => self::A_LEVEL,
        'a level' => self::A_LEVEL,
        'alevel' => self::A_LEVEL,
        'advanced level' => self::A_LEVEL,
        'form 6' => self::A_LEVEL,

        'certificate' => self::CERTIFICATE,
        'diploma' => self::DIPLOMA,
        'national diploma' => self::DIPLOMA,

        'undergraduate' => self::UNDERGRADUATE,
        'bachelor' => self::UNDERGRADUATE,
        'bachelors' => self::UNDERGRADUATE,
        'bachelor degree' => self::UNDERGRADUATE,
        'degree' => self::UNDERGRADUATE,
        'bsc' => self::UNDERGRADUATE,
        'ba' => self::UNDERGRADUATE,

        'honours degree' => self::HONOURS,
        'honours' => self::HONOURS,
        'honors' => self::HONOURS,
        'hons' => self::HONOURS,
        'bachelor honours' => self::HONOURS,

        'postgraduate' => self::POSTGRADUATE,
        'postgraduate diploma' => self::POSTGRADUATE,
        'pgd' => self::POSTGRADUATE,

        'masters' => self::MASTERS,
        'master' => self::MASTERS,
        'master degree' => self::MASTERS,
        'msc' => self::MASTERS,
        'ma' => self::MASTERS,
        'mba' => self::MASTERS,

        'phd' => self::PHD,
        'doctorate' => self::PHD,
        'doctoral' => self::PHD,
        'dphil' => self::PHD,
    ];

    private function __construct()
    {
    }

    /**
     * The canonical level a written value means, or null when nothing
     * recognises it - which a caller should treat as "unknown", never as a
     * specific level, the same way `ApplicationStatus::canonical()` treats a
     * value it cannot place.
     *
     * A value that is already one of the canonical constants is recognised
     * directly, before falling through to the legacy spelling table. Every
     * profile and listing saved going forward stores exactly these constants
     * ('O_LEVEL', not 'O Level'), so this is the common path, not a fallback -
     * LEGACY_MAP exists for rows written before this class did.
     */
    public static function canonical(?string $value): ?string
    {
        if ($value !== null && in_array($value, self::TARGET_LEVELS, true)) {
            return $value;
        }

        $key = self::normalise($value);

        return $key === null ? null : (self::LEGACY_MAP[$key] ?? null);
    }

    public static function tier(?string $value): ?string
    {
        $level = self::canonical($value);

        return $level === null ? null : (self::TIERS[$level] ?? null);
    }

    public static function label(?string $value): string
    {
        $level = self::canonical($value);

        return $level === null ? (string) $value : (self::LABELS[$level] ?? (string) $value);
    }

    public static function isPrimary(?string $value): bool
    {
        return self::canonical($value) === self::PRIMARY;
    }

    /** Whether a field of study is a meaningful concept at this level. */
    public static function usesFieldOfStudy(?string $value): bool
    {
        $tier = self::tier($value);

        return $tier === self::TIER_TERTIARY || $tier === self::TIER_POSTGRADUATE;
    }

    /** Whether "academic results" for this level means a school report (O/A-Level style) rather than a transcript. */
    public static function usesSchoolResults(?string $value): bool
    {
        $level = self::canonical($value);

        return $level === self::O_LEVEL || $level === self::A_LEVEL;
    }

    public static function usesTranscript(?string $value): bool
    {
        $tier = self::tier($value);

        return $tier === self::TIER_TERTIARY || $tier === self::TIER_POSTGRADUATE;
    }

    /**
     * Generous lower/upper age bounds per tier, for catching a profile that is
     * plainly inconsistent with itself - Primary plus an adult's date of
     * birth, Undergraduate plus a small child's - rather than for deciding who
     * may apply to what.
     *
     * This is deliberately not the same question as a scholarship's own
     * max_age/min_age rule. That is a provider's eligibility requirement,
     * enforced by EligibilityEvaluator against one specific listing. This is a
     * sanity check on the profile itself, enforced once at save time,
     * independent of any scholarship. A 45-year-old Undergraduate applicant is
     * a mature student and must not be blocked here - the upper bound on the
     * tertiary and postgraduate tiers is deliberately absent.
     *
     * @return array{0: int, 1: ?int} [minimum age, maximum age or null for no ceiling]
     */
    private const AGE_RANGES = [
        self::TIER_PRIMARY => [4, 16],
        self::TIER_SECONDARY => [9, 25],
        self::TIER_TERTIARY => [14, null],
        self::TIER_POSTGRADUATE => [18, null],
    ];

    /**
     * Whether an age makes sense for a level, or true when either is unknown -
     * an unanswered question is not a contradiction.
     */
    public static function isAgeConsistent(?string $level, ?int $age): bool
    {
        $tier = self::tier($level);

        if ($tier === null || $age === null) {
            return true;
        }

        [$min, $max] = self::AGE_RANGES[$tier];

        return $age >= $min && ($max === null || $age <= $max);
    }

    /** The sentence explaining an inconsistent age/level combination, or null when it is consistent. */
    public static function ageConsistencyReason(?string $level, ?int $age): ?string
    {
        if (self::isAgeConsistent($level, $age)) {
            return null;
        }

        return 'A date of birth giving an age of ' . $age . ' does not look right for '
            . self::label($level) . '. Double-check your date of birth and education level.';
    }

    private static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strtolower(trim($value));
        $clean = str_replace(['(', ')', '-', '/', '&', ',', '.', '—'], ' ', $clean);
        $clean = (string) preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);

        return $clean === '' ? null : $clean;
    }
}
