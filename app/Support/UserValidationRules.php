<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The rules for a name and a password, in one place.
 *
 * Registration, the administrator's create-user form and the applicant's profile each wrote their own copy of the name
 * rule and they had drifted: a provider's organisation name needed a letter at registration and nothing at all when an
 * administrator created the same account. A person's name and an organisation's name are different things, so there are
 * two rules - but each is now the same wherever it is asked for.
 */
final class UserValidationRules
{
    /** A person: letters, spaces, apostrophes, hyphens and full stops (FormOptions::NAME_PATTERN). */
    public static function personName(): array
    {
        return ['bail', 'required', 'string', 'max:255', 'regex:' . FormOptions::NAME_PATTERN];
    }

    /**
     * An organisation's registered name: free text (digits and ampersands are fine, see FormOptions::NAME_PATTERN's docblock),
     * but it has to be a name, so at least one letter.
     */
    public static function organisationName(): array
    {
        return ['bail', 'required', 'string', 'max:255', 'regex:/\p{L}/u'];
    }

    public static function password(): array
    {
        return ['bail', 'required', 'string', 'max:72', 'confirmed', 'regex:/[A-Z]/', Password::min(8)->letters()->numbers()];
    }
}
