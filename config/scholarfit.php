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
     *
     * Every figure below is on the ZIMSEC A-Level scale - A=5 down to E=1, so
     * five subjects at A is 25 and three at A is 15. They were previously set
     * against a scale on which an A was worth 12, which is not a scale ZIMSEC
     * has ever used; rescaling them was part of correcting the grades
     * themselves, because a threshold of 12 means "three good passes" on one
     * scale and "one A" on the other.
     */
    'academic' => [
        /* Points clear of the floor that earn full marks, on a 3-subject total of 15. */
        'headroom_points' => 3,
        'at_floor' => 0.7,
        'strong_record' => 1.0,
        'sound_record' => 0.7,
        'thin_record' => 0.4,
        /* A-Level points at or above which a record stands on its own with no floor to beat. */
        'strong_points' => 13,
        'sound_points' => 8,
        /*
         * Subjects at or above which an unpointed record - O-Level, Cambridge,
         * Primary - reads as a sound one. These qualifications have no point
         * scale, so their strength is how complete the record is rather than a
         * total converted out of a scale their board does not use.
         */
        'sound_subjects' => 5,
    ],

    /*
     * Location tiers. Country dropped out of this hierarchy when ScholarZim
     * became Zimbabwe-only - every applicant and every listing is implicitly
     * Zimbabwean, so there is no longer a country tier to score. What remains
     * is province -> locality (a specific place) -> settlement type (rural or
     * urban), each narrower than the last and scored only when the listing
     * actually targets it. The three must sum to 1.0.
     */
    'location' => [
        'province' => 0.7,
        'locality' => 0.2,
        'settlement_type' => 0.1,
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
