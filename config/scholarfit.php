<?php

/**
 * ScholarFit defaults.
 *
 * These are the weights the Spring implementation used, kept here so scores stay
 * comparable with archived reports. An administrator can override them at
 * /admin/scholarfit; the override lives in platform_settings and is read through
 * SettingsService, which falls back to this file whenever no override is stored.
 *
 * Everything a matcher multiplies a weight by lives here too. The v1 engine had
 * these as private constants scattered through one 600-line class, which is how
 * it ended up crediting 53% of the location weight for a listing that named no
 * location at all - a number nobody had to defend because nobody could see it
 * next to the others.
 */
return [

    /*
     * Weights must sum to 100 - the engine validates this before it will accept
     * an override, because every score is presented to students as "out of 100".
     * Summing to 100 is also what makes the total normalised by construction:
     * each dimension contributes ratio x weight, so the total cannot exceed 100
     * without a clamp hiding the arithmetic.
     */
    'weights' => [
        'academic' => 20,
        'education_level' => 25,
        'field' => 25,
        'location' => 15,
        'deadline' => 10,
        'certificate' => 5,
    ],

    /*
     * Credit fractions, applied to a dimension's weight.
     *
     * `related` is a near miss the applicant genuinely part-satisfies: an
     * adjacent education level, a field in the same canonical family.
     *
     * `neutral` is the answer to "the provider did not say". It is deliberately
     * a half mark and deliberately not 1.0: an unstated requirement is unknown,
     * not satisfied, and scoring silence as a perfect match is how v1 floated
     * sparse listings to the top of everyone's recommendations.
     *
     * `distant` is two steps along the education ladder - still conceivable,
     * clearly not a match.
     */
    'credit' => [
        'related' => 0.6,
        'neutral' => 0.5,
        'distant' => 0.25,
    ],

    /*
     * Academic strength, as a fraction of the academic weight.
     *
     * When a listing states a points floor the score is earned against that
     * floor: meeting it exactly is a pass rather than a triumph, and clearing it
     * by `headroom_points` or more is full marks. When no floor is stated the
     * record is graded on its own merits instead.
     */
    'academic' => [
        'headroom_points' => 5,
        'at_floor' => 0.7,
        'strong_record' => 1.0,
        'sound_record' => 0.7,
        'thin_record' => 0.4,
        /* Points at or above which a record stands on its own with no floor to beat. */
        'strong_points' => 12,
        'sound_points' => 6,
        'strong_gpa' => 3.0,
        'sound_gpa' => 2.0,
    ],

    /*
     * Location tiers. The hierarchy is country -> province -> district ->
     * locality, each narrower than the last, and each scored only when the
     * listing actually targets it.
     */
    'location' => [
        'country' => 0.6,
        'province' => 0.25,
        'district' => 0.1,
        'locality' => 0.05,
    ],

    /*
     * Deadline urgency. A closing deadline is the one worth surfacing first,
     * so sooner scores higher - but a listing with no deadline at all is
     * unknown rather than urgent, and gets the neutral half mark.
     */
    'deadline' => [
        'closing_days' => 14,
        'soon_days' => 30,
        'closing' => 1.0,
        'soon' => 0.8,
        'distant' => 0.5,
    ],

    /* Score at or above which a match is labelled "strong" / "possible". */
    'confidence' => [
        'high' => 75,
        'medium' => 50,
    ],

    /* How long a computed ranking is cached for, in minutes. */
    'cache_ttl_minutes' => 60,
];
