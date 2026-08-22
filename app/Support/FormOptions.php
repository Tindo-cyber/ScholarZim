<?php

namespace App\Support;

final class FormOptions
{
    public const DEFAULT_COUNTRY = 'Zimbabwe';

    public const PRIMARY_GRADES = [
        'Primary — Grade 1',
        'Primary — Grade 2',
        'Primary — Grade 3',
        'Primary — Grade 4',
        'Primary — Grade 5',
        'Primary — Grade 6',
        'Primary — Grade 7',
    ];

    public const SECONDARY_FORMS = [
        'Secondary — Form 1',
        'Secondary — Form 2',
        'Secondary — Form 3',
        'Secondary — Form 4',
        'Secondary — Form 5',
        'Secondary — Form 6',
    ];

    public const TERTIARY_LEVELS = [
        'High School (O-Level)',
        'High School (A-Level)',
        'Certificate',
        'Diploma',
        'Undergraduate',
        'Honours Degree',
        'Postgraduate',
        'Masters',
        'PhD',
    ];

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

    public const COUNTRIES = [
        'Zimbabwe',
        'South Africa',
        'Botswana',
        'Namibia',
        'Zambia',
        'Mozambique',
        'Malawi',
        'Kenya',
        'United Kingdom',
        'United States',
        'Canada',
        'Australia',
        'Germany',
        'China',
    ];

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
    ];

    public const FUNDING_TYPES = [
        'Full Scholarship',
        'Partial Scholarship',
        'Tuition Only',
        'Tuition + Accommodation',
        'Monthly Stipend',
        'Research Grant',
    ];

    private function __construct()
    {
    }

    /** Every level, primary through PhD, in the order the selects render them. */
    public static function educationLevels(): array
    {
        return array_merge(self::PRIMARY_GRADES, self::SECONDARY_FORMS, self::TERTIARY_LEVELS);
    }

    /** Grouped variant so the selects can use optgroups. */
    public static function educationLevelGroups(): array
    {
        return [
            'Primary' => self::PRIMARY_GRADES,
            'Secondary' => self::SECONDARY_FORMS,
            'Tertiary' => self::TERTIARY_LEVELS,
        ];
    }
}
