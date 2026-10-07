<?php

namespace App\Support;

/**
 * Wording for the errors on the provider's listing form.
 *
 * Laravel's defaults name the field path, so a failed subject row came back as
 * "subject_requirements.1.qualification_id is required" - accurate and useless
 * to a provider looking at a grid of dropdowns. These say which row and what to
 * do, and are keyed by wildcard so they cover every row.
 *
 * ":position" is the row's place in the list (1 for the first). That only matches
 * what the provider sees if the rows are numbered 0..n-1 in the order they are
 * shown, which is why OpportunityController renumbers them before validating:
 * removing a row in the browser leaves a gap in the keys, and the gap would
 * otherwise make "Row 4" refer to the second row on screen.
 *
 * Kept in one place so the create and update requests word these identically.
 */
final class OpportunityFormMessages
{
    private function __construct()
    {
    }

    /** @return array<string, string> */
    public static function subjectRequirements(): array
    {
        return [
            'subject_requirements.array' => 'The subject requirements were not sent in the expected form. Reload the page and try again.',

            'subject_requirements.*.qualification_id.required' => 'Row :position: choose a qualification.',
            'subject_requirements.*.qualification_id.integer' => 'Row :position: choose a qualification.',
            'subject_requirements.*.qualification_id.exists' => 'Row :position: that qualification is not available. Choose another.',

            'subject_requirements.*.subject_id.required' => 'Row :position: choose a subject.',
            'subject_requirements.*.subject_id.integer' => 'Row :position: choose a subject.',
            'subject_requirements.*.subject_id.exists' => 'Row :position: that subject is not available. Choose another.',

            'subject_requirements.*.minimum_grade.string' => 'Row :position: choose a grade from the list.',
            'subject_requirements.*.minimum_grade.max' => 'Row :position: choose a grade from the list.',
        ];
    }
}
