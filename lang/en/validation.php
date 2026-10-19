<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'Please tick the box to confirm :attribute.',
    'accepted_if' => ':Attribute must be accepted when :other is :value.',
    'active_url' => ':Attribute must be a valid URL.',
    'after' => ':Attribute must be a date after :date.',
    'after_or_equal' => ':Attribute must be a date after or equal to :date.',
    'alpha' => ':Attribute must only contain letters.',
    'alpha_dash' => ':Attribute must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => ':Attribute must only contain letters and numbers.',
    'any_of' => ':Attribute is invalid.',
    'array' => 'Please check what you entered for :attribute.',
    'ascii' => ':Attribute must only contain single-byte alphanumeric characters and symbols.',
    'before' => ':Attribute must be a date before :date.',
    'before_or_equal' => ':Attribute must be a date before or equal to :date.',
    'between' => [
        'array' => ':Attribute must have between :min and :max items.',
        'file' => ':Attribute must be between :min and :max kilobytes.',
        'numeric' => ':Attribute must be between :min and :max.',
        'string' => ':Attribute must be between :min and :max characters.',
    ],
    'boolean' => 'Please check what you chose for :attribute.',
    'can' => ':Attribute contains an unauthorized value.',
    'confirmed' => ':Attribute confirmation does not match.',
    'contains' => ':Attribute is missing a required value.',
    'current_password' => 'The password is incorrect.',
    'date' => 'Please enter a valid date for :attribute.',
    'date_equals' => ':Attribute must be a date equal to :date.',
    'date_format' => ':Attribute must match the format :format.',
    'decimal' => ':Attribute must have :decimal decimal places.',
    'declined' => ':Attribute must be declined.',
    'declined_if' => ':Attribute must be declined when :other is :value.',
    'different' => ':Attribute and :other must be different.',
    'digits' => ':Attribute must be :digits digits.',
    'digits_between' => ':Attribute must be between :min and :max digits.',
    'dimensions' => ':Attribute has invalid image dimensions.',
    'distinct' => ':Attribute has a duplicate value.',
    'doesnt_contain' => ':Attribute must not contain any of the following: :values.',
    'doesnt_end_with' => ':Attribute must not end with one of the following: :values.',
    'doesnt_start_with' => ':Attribute must not start with one of the following: :values.',
    'email' => 'Please enter a valid email address.',
    'encoding' => ':Attribute must be encoded in :encoding.',
    'ends_with' => ':Attribute must end with one of the following: :values.',
    'enum' => 'Please choose a valid option for :attribute.',
    'exists' => 'Please choose a valid option for :attribute.',
    'extensions' => ':Attribute must have one of the following extensions: :values.',
    'file' => 'Please choose a file for :attribute.',
    'filled' => ':Attribute must have a value.',
    'gt' => [
        'array' => ':Attribute must have more than :value items.',
        'file' => ':Attribute must be greater than :value kilobytes.',
        'numeric' => ':Attribute must be greater than :value.',
        'string' => ':Attribute must be greater than :value characters.',
    ],
    'gte' => [
        'array' => ':Attribute must have :value items or more.',
        'file' => ':Attribute must be greater than or equal to :value kilobytes.',
        'numeric' => ':Attribute must be greater than or equal to :value.',
        'string' => ':Attribute must be greater than or equal to :value characters.',
    ],
    'hex_color' => ':Attribute must be a valid hexadecimal color.',
    'image' => ':Attribute must be an image.',
    'in' => 'Please choose a valid option for :attribute.',
    'in_array' => ':Attribute must exist in :other.',
    'in_array_keys' => ':Attribute must contain at least one of the following keys: :values.',
    'integer' => 'Please enter a whole number for :attribute.',
    'ip' => ':Attribute must be a valid IP address.',
    'ipv4' => ':Attribute must be a valid IPv4 address.',
    'ipv6' => ':Attribute must be a valid IPv6 address.',
    'json' => ':Attribute must be a valid JSON string.',
    'list' => ':Attribute must be a list.',
    'lowercase' => ':Attribute must be lowercase.',
    'lt' => [
        'array' => ':Attribute must have less than :value items.',
        'file' => ':Attribute must be less than :value kilobytes.',
        'numeric' => ':Attribute must be less than :value.',
        'string' => ':Attribute must be less than :value characters.',
    ],
    'lte' => [
        'array' => ':Attribute must not have more than :value items.',
        'file' => ':Attribute must be less than or equal to :value kilobytes.',
        'numeric' => ':Attribute must be less than or equal to :value.',
        'string' => ':Attribute must be less than or equal to :value characters.',
    ],
    'mac_address' => ':Attribute must be a valid MAC address.',
    'max' => [
        'array' => ':Attribute must not have more than :max items.',
        'file' => ':Attribute must not be greater than :max kilobytes.',
        'numeric' => ':Attribute must not be greater than :max.',
        'string' => ':Attribute must not be greater than :max characters.',
    ],
    'max_digits' => ':Attribute must not have more than :max digits.',
    'mimes' => ':Attribute must be a file of type: :values.',
    'mimetypes' => ':Attribute must be a file of type: :values.',
    'min' => [
        'array' => ':Attribute must have at least :min items.',
        'file' => ':Attribute must be at least :min kilobytes.',
        'numeric' => ':Attribute must be at least :min.',
        'string' => ':Attribute must be at least :min characters.',
    ],
    'min_digits' => ':Attribute must have at least :min digits.',
    'missing' => ':Attribute must be missing.',
    'missing_if' => ':Attribute must be missing when :other is :value.',
    'missing_unless' => ':Attribute must be missing unless :other is :value.',
    'missing_with' => ':Attribute must be missing when :values is present.',
    'missing_with_all' => ':Attribute must be missing when :values are present.',
    'multiple_of' => ':Attribute must be a multiple of :value.',
    'not_in' => 'Please choose a valid option for :attribute.',
    'not_regex' => 'Please check the format of :attribute.',
    'numeric' => ':Attribute must be a number.',
    'password' => [
        'letters' => ':Attribute must contain at least one letter.',
        'mixed' => ':Attribute must contain at least one uppercase and one lowercase letter.',
        'numbers' => ':Attribute must contain at least one number.',
        'symbols' => ':Attribute must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'present' => ':Attribute must be present.',
    'present_if' => ':Attribute must be present when :other is :value.',
    'present_unless' => ':Attribute must be present unless :other is :value.',
    'present_with' => ':Attribute must be present when :values is present.',
    'present_with_all' => ':Attribute must be present when :values are present.',
    'prohibited' => ':Attribute is prohibited.',
    'prohibited_if' => ':Attribute is prohibited when :other is :value.',
    'prohibited_if_accepted' => ':Attribute is prohibited when :other is accepted.',
    'prohibited_if_declined' => ':Attribute is prohibited when :other is declined.',
    'prohibited_unless' => ':Attribute is prohibited unless :other is in :values.',
    'prohibits' => ':Attribute prohibits :other from being present.',
    'regex' => 'Please check the format of :attribute.',
    'required' => 'Please provide :attribute.',
    'required_array_keys' => ':Attribute must contain entries for: :values.',
    'required_if' => 'Please provide :attribute.',
    'required_if_accepted' => 'Please provide :attribute.',
    'required_if_declined' => 'Please provide :attribute.',
    'required_unless' => 'Please provide :attribute.',
    'required_with' => 'Please provide :attribute.',
    'required_with_all' => 'Please provide :attribute.',
    'required_without' => 'Please provide :attribute.',
    'required_without_all' => 'Please provide :attribute.',
    'same' => ':Attribute must match :other.',
    'size' => [
        'array' => ':Attribute must contain :size items.',
        'file' => ':Attribute must be :size kilobytes.',
        'numeric' => ':Attribute must be :size.',
        'string' => ':Attribute must be :size characters.',
    ],
    'starts_with' => ':Attribute must start with one of the following: :values.',
    'string' => ':Attribute must be a string.',
    'timezone' => ':Attribute must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'uppercase' => ':Attribute must be uppercase.',
    'url' => ':Attribute must be a valid URL.',
    'ulid' => ':Attribute must be a valid ULID.',
    'uuid' => ':Attribute must be a valid UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'current_programme_id' => [
            'required' => 'Please choose your programme from the list.',
            'integer' => 'Please choose your programme from the list.',
        ],
        'profile.current_programme_id' => [
            'required' => 'Please choose your programme from the list.',
            'integer' => 'Please choose your programme from the list.',
        ],
        'gender' => [
            'required' => 'Please select your gender.',
            'in' => 'Please select your gender.',
        ],
        'profile.gender' => [
            'required' => 'Please select your gender.',
            'in' => 'Please select your gender.',
        ],
        'full_name' => [
            'required' => 'Please enter your full name.',
        ],
        'profile.applied_institution_ids' => [
            'required' => 'Please choose at least one institution.',
            'min' => 'Please choose at least one institution.',
            'max' => 'Please choose no more than three institutions.',
        ],
        'academic_subject_results' => [
            'max' => 'You can record up to 40 subjects. Please remove some.',
        ],
        'academic_subject_results.*.qualification_id' => [
            'required' => 'Row :position: please choose a qualification.',
            'integer' => 'Row :position: please choose a qualification.',
            'exists' => 'Row :position: please choose a qualification from the list.',
        ],
        'academic_subject_results.*.subject_id' => [
            'required' => 'Row :position: please select a subject.',
            'integer' => 'Row :position: please select a subject.',
            'exists' => 'Row :position: please select a subject from the list.',
        ],
        'academic_subject_results.*.result' => [
            'required' => 'Row :position: please enter the grade or result for this subject.',
            'string' => 'Row :position: please enter the grade or result for this subject.',
            'max' => 'Row :position: that grade or result is too long.',
        ],
        'academic_subject_results.*.year' => [
            'integer' => 'Row :position: please enter the year as a number, for example 2024.',
            'min' => 'Row :position: please enter a year from 1950 onwards.',
            'max' => 'Row :position: that year is too far ahead.',
        ],
        'subject_requirements.*.qualification_id' => [
            'required' => 'Row :position: please choose a qualification.',
            'exists' => 'Row :position: please choose a qualification from the list.',
        ],
        'subject_requirements.*.subject_id' => [
            'required' => 'Row :position: please select a subject.',
            'exists' => 'Row :position: please select a subject from the list.',
        ],
        'subject_requirements.*.minimum_grade' => [
            'required' => 'Row :position: please choose the lowest grade accepted.',
        ],
        'documents.*' => [
            'required' => 'Please choose a file for :attribute.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'full_name' => 'your full name',
        'email' => 'your email address',
        'phone' => 'your phone number',
        'password' => 'your password',
        'password_confirmation' => 'the password confirmation',
        'date_of_birth' => 'your date of birth',
        'gender' => 'your gender',
        'education_level' => 'your education level',
        'institution_name' => 'your school or institution',
        'field_of_study' => 'your field of study',
        'province' => 'your province',
        'locality' => 'your town or city',
        'settlement_type' => 'your home area',
        'guardian_name' => 'your guardian\'s name',
        'guardian_phone' => 'your guardian\'s phone number',
        'guardian_relationship' => 'your relationship to your guardian',
        'guardian_confirmed' => 'the guardian confirmation',
        'biography' => 'your short biography',
        'degree_classification' => 'your degree classification',
        'profile.full_name' => 'your full name',
        'profile.email' => 'your email address',
        'profile.phone' => 'your phone number',
        'profile.password' => 'your password',
        'profile.password_confirmation' => 'the password confirmation',
        'profile.date_of_birth' => 'your date of birth',
        'profile.gender' => 'your gender',
        'profile.education_level' => 'your education level',
        'profile.institution_name' => 'your school or institution',
        'profile.field_of_study' => 'your field of study',
        'profile.province' => 'your province',
        'profile.locality' => 'your town or city',
        'profile.settlement_type' => 'your home area',
        'profile.guardian_name' => 'your guardian\'s name',
        'profile.guardian_phone' => 'your guardian\'s phone number',
        'profile.guardian_relationship' => 'your relationship to your guardian',
        'profile.guardian_confirmed' => 'the guardian confirmation',
        'profile.biography' => 'your short biography',
        'profile.degree_classification' => 'your degree classification',
        'profile.applied_institution_ids' => 'the institutions you have applied to',
        'applied_institution_ids' => 'the institutions you have applied to',
        'personal_statement' => 'your personal statement',
        'confirm' => 'the confirmation',
        'document' => 'the attachment',
        'reason' => 'the reason',
        'academic_subject_results' => 'your academic results',
        'academic_subject_results.*.qualification_id' => 'the qualification',
        'academic_subject_results.*.subject_id' => 'the subject',
        'academic_subject_results.*.result' => 'the result',
        'academic_subject_results.*.year' => 'the year',
        'subject_requirements.*.qualification_id' => 'the qualification',
        'subject_requirements.*.subject_id' => 'the subject',
        'subject_requirements.*.minimum_grade' => 'the lowest grade accepted',
        'documents.results' => 'the results certificate',
        'documents.transcript' => 'the academic certificate or proof of study',
        'documents.passport' => 'the ID or passport',
        'documents.recommendation' => 'the recommendation letter',
        'documents.cv' => 'the CV',
        'documents.grade7_slip' => 'the Grade 7 results slip',
        'intended_programme_ids' => 'the programmes you hope to study',
        'target_field_ids' => 'the fields you want to study next',
        'current_programme_id' => 'your programme',
        'profile.current_programme_id' => 'your programme',
    ],

];
