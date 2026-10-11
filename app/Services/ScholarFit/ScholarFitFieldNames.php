<?php

namespace App\Services\ScholarFit;

/**
 * The profile fields ScholarFit can be missing, by the name it gives them, and
 * where in the profile form each one is filled in.
 *
 * A "needs information" outcome carries the field's plain name ("date of birth")
 * so a sentence can say what to add; the page that lists such listings needs the
 * same name to link to the right place on the profile. Keeping both here means
 * they cannot drift: a field the evaluator can ask for is a field the profile
 * page has an anchor for.
 */
final class ScholarFitFieldNames
{
    public const DATE_OF_BIRTH = 'date of birth';

    public const PROVINCE = 'province';

    public const LOCALITY = 'locality';

    public const SETTLEMENT_TYPE = 'settlement type';

    public const FIELD_OF_STUDY = 'field of study';

    public const PROGRAMME = 'programme';

    public const EDUCATION_LEVEL = 'education level';

    public const ACADEMIC_RESULTS = 'academic results';

    public const INSTITUTION = 'institution';

    /** Documents the student can upload to their profile. */
    public const GRADE7_SLIP = 'Grade 7 results slip';

    public const RESULTS_CERTIFICATE = 'results certificate';

    public const TRANSCRIPT = 'transcript';

    private const ANCHORS = [
        self::DATE_OF_BIRTH => 'date_of_birth',
        self::PROVINCE => 'province',
        self::LOCALITY => 'locality',
        self::SETTLEMENT_TYPE => 'settlement_type',
        self::FIELD_OF_STUDY => 'field_of_study',
        self::PROGRAMME => 'programme-card',
        self::EDUCATION_LEVEL => 'education_level',
        self::ACADEMIC_RESULTS => 'academic-results',
        self::INSTITUTION => 'programme-card',
        self::GRADE7_SLIP => 'grade7-slip-card',
        self::RESULTS_CERTIFICATE => 'documents',
        self::TRANSCRIPT => 'documents',
    ];

    private function __construct()
    {
    }

    public static function anchor(string $field): ?string
    {
        return self::ANCHORS[$field] ?? null;
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_keys(self::ANCHORS);
    }
}
