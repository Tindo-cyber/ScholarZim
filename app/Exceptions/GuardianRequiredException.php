<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an applicant under 18 tries to submit an application without a parent or guardian's details.
 *
 * Only submitting is blocked - a minor can browse, see recommendations and build a profile without them.
 * A RuntimeException for the same reason ProfileIncompleteException is: every applicant-facing controller
 * already reports one to the user.
 */
class GuardianRequiredException extends RuntimeException
{
    /** @param array<int, string> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct(
            'You are under 18, so a parent or guardian has to be involved before you can apply. Please add '
            . implode(', ', $missing) . ' on your profile (Guardian section).'
        );
    }
}
