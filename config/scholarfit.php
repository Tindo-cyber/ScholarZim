<?php

/**
 * ScholarFit defaults.
 *
 * These are the weights the Spring implementation used, kept here so scores stay
 * comparable with archived reports. An administrator can override them at
 * /admin/scholarfit; the override lives in platform_settings and is read through
 * SettingsService, which falls back to this file whenever no override is stored.
 */
return [

    /*
     * Weights must sum to 100 - the engine validates this before it will accept
     * an override, because every score is presented to students as "out of 100".
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
     * Partial credit for a near miss, as a fraction of the dimension's weight:
     * a related field of study or an adjacent education level earns this much of
     * the full weight instead of zero.
     */
    'related_credit' => 0.6,

    /* Score at or above which a match is labelled "strong" / "possible". */
    'confidence' => [
        'high' => 75,
        'medium' => 50,
    ],

    /* How long a computed ranking is cached for, in minutes. */
    'cache_ttl_minutes' => 60,
];
