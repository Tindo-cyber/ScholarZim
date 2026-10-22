<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A provider tried to accept an application when every award slot is already taken.
 *
 * A RuntimeException, like InvalidApplicationTransition, so callers that report a refused business rule keep working;
 * its own type so the review screen can answer it with a message instead of letting it surface as a server error.
 * Acceptances are final, so a filled listing stays filled.
 */
class AwardsFilled extends RuntimeException
{
    public static function forListing(): self
    {
        return new self('This scholarship has already filled all of its available awards. You cannot accept another application.');
    }
}
