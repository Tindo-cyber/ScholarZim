<?php

namespace App\Support;

/**
 * How long each free-text field may be, for the form's own `maxlength`.
 *
 * The server is the authority: every figure here is the `max:` in that field's validation rule, and FormInputAuditTest
 * checks the two agree. The browser's limit is only a kindness - it stops a person typing or pasting past what would be
 * refused, instead of telling them after the page has been sent - so a field not listed here simply has none.
 *
 * Used by the form input and textarea components when a page has not set a `maxlength` of its own.
 */
final class FieldLimits
{
    /** @var array<string, int> field name => the most characters the server accepts */
    public const MAX = [
        'full_name' => 255,
        'email' => 254,
        'password' => 72,
        'confirm_email' => 254,
        'institution_name' => 255,
        'field_of_study' => 255,
        'locality' => 100,
        'target_locality' => 100,
        'guardian_name' => 255,
        'guardian_relationship' => 100,
        'biography' => 5000,
        'personal_statement' => 5000,
        'title' => 255,
        'description' => 10000,
        'provider_display_name' => 255,
        'external_url' => 500,
        'other_conditions' => 3000,
        'host_institutions' => 500,
        'reason' => 500,
        'note' => 1000,
        'details' => 1000,
        'message' => 1000,
        'contact_name' => 255,
        'contact_role' => 150,
        'registration_number' => 100,
        'registration_number_note' => 500,
    ];

    public static function for(string $field): ?int
    {
        return self::MAX[$field] ?? null;
    }
}
