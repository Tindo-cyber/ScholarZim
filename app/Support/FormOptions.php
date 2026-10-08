<?php

namespace App\Support;

final class FormOptions
{
    /**
     * ScholarZim is a Zimbabwe-only platform, so this is a fact about the
     * product rather than something an applicant or a provider chooses. It
     * remains as a constant because a handful of places still read it as a
     * default for a legacy `country`/`target_country` column value; nothing
     * new should present it as a form field. See docs/user-guide.md and
     * App\Support\EducationLevel for the fuller explanation.
     */
    public const DEFAULT_COUNTRY = 'Zimbabwe';

    /**
     * The catalogue fields a provider can mistake for "all engineering". 071 is electrical, mechanical and other
     * engineering; civil engineering is filed under 073 and mining under 072, both of which 07 (the broad field) covers.
     */
    public const ENGINEERING_NARROW = '071';

    public const ENGINEERING_BROAD = '07';

    public const FIELDS_OF_STUDY = [
        'Computer Science & IT',
        'Engineering',
        'Medicine & Health Sciences',
        'Law',
        'Business & Finance',
        'Education',
        'Agriculture & Agribusiness',
        'Arts & Humanities',
        'Natural Sciences',
        'Social Sciences',
        'Nursing',
        'Accounting',
        'Environmental Science',
        'Mining & Metallurgy',
        'General Primary',
        'General Secondary',
    ];

    /**
     * A listing for study that is not tied to one country: "Any country" for an
     * award the holder may take wherever they are admitted, "Online / distance"
     * for study with no place to travel to. Neither is a place, so neither gets a
     * town or a province in its label, and a search for a real country includes
     * them (see Opportunity::scopeMatchingFilters) - an applicant looking for
     * awards they can use in Germany should still see one usable anywhere.
     */
    public const COUNTRY_ANY = 'Any country';

    public const COUNTRY_ONLINE = 'Online / distance';

    /**
     * Where a scholarship is held. Zimbabwe first because it is the default and
     * most listings; the two flexible entries come last. Country is not an
     * eligibility rule - ScholarFit never reads it - it is what the listing says
     * and what search can filter on.
     */
    public const COUNTRIES = [
        'Zimbabwe',
        'South Africa',
        'Botswana',
        'Namibia',
        'Zambia',
        'Mauritius',
        'Kenya',
        'United Kingdom',
        'United States',
        'Canada',
        'Australia',
        'China',
        'India',
        'Russia',
        'Hungary',
        'Germany',
        'Turkey',
        'Malaysia',
        'Egypt',
        'Japan',
        self::COUNTRY_ANY,
        self::COUNTRY_ONLINE,
    ];

    /** The entries that are not a place. */
    public const FLEXIBLE_COUNTRIES = [self::COUNTRY_ANY, self::COUNTRY_ONLINE];

    public const ZIMBABWE_PROVINCES = [
        'Bulawayo',
        'Harare',
        'Manicaland',
        'Mashonaland Central',
        'Mashonaland East',
        'Mashonaland West',
        'Masvingo',
        'Matabeleland North',
        'Matabeleland South',
        'Midlands',
    ];

    /**
     * Autocomplete suggestions for the institution field, not a restriction -
     * `institution_name` is free text (see `ApplicantProfile`), because most
     * ScholarZim applicants attend a school this list was never going to cover
     * and forcing a choice from a fixed list would have locked them out of
     * completing their profile at all. A handful of well-known schools are
     * included alongside the universities and polytechnics so the suggestions
     * are useful to a Primary or O/A-Level applicant too, not only a
     * university one.
     */
    public const INSTITUTIONS = [
        'University of Zimbabwe (UZ)',
        'National University of Science and Technology (NUST)',
        'Midlands State University (MSU)',
        'Chinhoyi University of Technology (CUT)',
        'Great Zimbabwe University (GZU)',
        'Bindura University of Science Education (BUSE)',
        'Lupane State University (LSU)',
        'Zimbabwe Open University (ZOU)',
        'Harare Institute of Technology (HIT)',
        'Solusi University',
        'Catholic University of Zimbabwe',
        'Africa University',
        'Bulawayo Polytechnic',
        'Harare Polytechnic',
        'Gweru Polytechnic',
        'Mutare Polytechnic',
        'Prince Edward School',
        'Churchill High School',
        'St George\'s College',
        'Mount Pleasant High School',
        'Founders High School',
        'Girls High School',
    ];

    public const FUNDING_TYPES = [
        'Full Scholarship',
        'Partial Scholarship',
        'Tuition Only',
        'Tuition + Accommodation',
        'Monthly Stipend',
        'Research Grant',
    ];

    public const CURRENCIES = [
        'USD',
        'ZWG',
        'ZAR',
        'GBP',
        'EUR',
    ];

    public const DEFAULT_CURRENCY = 'USD';

    /** Citizenship values a provider can require, and an applicant can claim. */
    public const CITIZENSHIPS = [
        'Zimbabwean',
        'South African',
        'Zambian',
        'Malawian',
        'Mozambican',
        'Botswanan',
        'Other',
    ];

    /**
     * Result orderings offered on the browse pages. The key is what appears in
     * ?sort=, and Opportunity::scopeSorted() is the only place that reads it -
     * anything unrecognised falls back to DEFAULT_SORT rather than erroring.
     */
    public const SORT_OPTIONS = [
        'newest' => 'Newest first',
        'deadline' => 'Deadline (soonest)',
        'award_desc' => 'Award value (highest)',
        'award_asc' => 'Award value (lowest)',
        'title' => 'Title (A-Z)',
    ];

    public const DEFAULT_SORT = 'newest';

    /** Sorts that only make sense once a signed-in applicant has a profile. */
    public const MATCH_SORT = 'match';

    /**
     * A person's name: letters (any script - \p{L} rather than A-Z, so a
     * name is not assumed to be English), spaces, hyphens and apostrophes -
     * "Anne-Marie" and "O'Brien" are ordinary names, not exceptions. No
     * digits and no other punctuation. Applied only where a field actually
     * holds a person's own name (an applicant, a guardian, an
     * admin-created account) - never to `full_name` on provider
     * registration, which this product uses as the organisation or
     * contact-person name ("Chikafu Education Trust") and is free text for
     * that reason.
     */
    public const NAME_PATTERN = "/^[\p{L}\s'\-]+$/u";

    /**
     * A Zimbabwean local phone number: exactly ten digits, nothing else -
     * no country code, no spaces, no dashes. Deliberately not normalised
     * from another format (e.g. stripping a leading +263) - the platform
     * has no established normalisation step today, and silently rewriting
     * what an applicant typed is a different feature from validating it.
     *
     * Applied to an applicant's own phone number and a guardian's, never to
     * a provider's - a provider's work/organisation number is international
     * and may be a landline or carry an extension, which this pattern
     * cannot express without becoming the kind of all-formats regex it was
     * written to avoid being. See App\Rules\InternationalPhoneNumber for
     * that rule instead.
     */
    public const PHONE_PATTERN = '/^\d{10}$/';

    private function __construct()
    {
    }

    /**
     * Every level an applicant's own profile may hold, as value => label,
     * canonical constant to display label - see App\Support\EducationLevel,
     * which is the actual source of truth this defers to entirely. FORM_1 is
     * excluded on purpose: it exists only as a scholarship target (a listing
     * "for Form 1 entrants"), never as something an applicant's own current
     * level is set to.
     */
    public static function educationLevels(): array
    {
        return array_combine(
            EducationLevel::APPLICANT_LEVELS,
            array_map(EducationLevel::label(...), EducationLevel::APPLICANT_LEVELS)
        );
    }

    /**
     * Every level a scholarship may target - the same list plus FORM_1, which
     * is a legitimate thing to aim a *listing* at even though no applicant's
     * profile is ever set to it.
     */
    public static function targetEducationLevels(): array
    {
        return array_combine(
            EducationLevel::TARGET_LEVELS,
            array_map(EducationLevel::label(...), EducationLevel::TARGET_LEVELS)
        );
    }

    /** Grouped variant so the applicant-facing select can use optgroups. */
    public static function educationLevelGroups(): array
    {
        return [
            'Primary' => [EducationLevel::PRIMARY => EducationLevel::label(EducationLevel::PRIMARY)],
            'Secondary' => [
                EducationLevel::O_LEVEL => EducationLevel::label(EducationLevel::O_LEVEL),
                EducationLevel::A_LEVEL => EducationLevel::label(EducationLevel::A_LEVEL),
            ],
            'Tertiary' => [
                EducationLevel::CERTIFICATE => EducationLevel::label(EducationLevel::CERTIFICATE),
                EducationLevel::DIPLOMA => EducationLevel::label(EducationLevel::DIPLOMA),
                EducationLevel::UNDERGRADUATE => EducationLevel::label(EducationLevel::UNDERGRADUATE),
            ],
            'Postgraduate' => [
                EducationLevel::POSTGRADUATE => EducationLevel::label(EducationLevel::POSTGRADUATE),
                EducationLevel::MASTERS => EducationLevel::label(EducationLevel::MASTERS),
                EducationLevel::PHD => EducationLevel::label(EducationLevel::PHD),
            ],
        ];
    }

    /**
     * The level options a PROVIDER chooses from, worded so each can be picked correctly.
     *
     * There is no "Honours" option. A Zimbabwean BSc / BCom Honours is a bachelor's degree
     * (Undergraduate); the one-year honours after a degree, as in South Africa, is
     * Postgraduate. Saying so beside the two options is what stops a provider guessing.
     *
     * @return array<string, array<string, string>>
     */
    public static function providerLevelGroups(bool $forTarget = true): array
    {
        $groups = $forTarget ? self::targetEducationLevelGroups() : self::educationLevelGroups();

        $groups['Tertiary'][EducationLevel::UNDERGRADUATE] = 'Undergraduate (a bachelor\'s degree, including BSc / BCom Honours at Zimbabwean universities)';
        $groups['Postgraduate'][EducationLevel::POSTGRADUATE] = 'Postgraduate (including a one-year Honours after a degree, e.g. in South Africa)';

        return $groups;
    }

    /** The same groups, for a scholarship's target level - includes Form 1 as its own entry point. */
    public static function targetEducationLevelGroups(): array
    {
        $groups = self::educationLevelGroups();
        $groups['Primary'] = [EducationLevel::FORM_1 => EducationLevel::label(EducationLevel::FORM_1)];

        return $groups;
    }
}
