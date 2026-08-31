<?php

namespace App\Exceptions;

use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use RuntimeException;

/**
 * A refused listing state change, phrased for whoever attempted it.
 *
 * Extends RuntimeException so the existing controller catches - which already
 * turn a RuntimeException into a flash message on the provider and admin
 * screens - report it without being taught a new exception type.
 */
class InvalidOpportunityTransition extends RuntimeException
{
    public static function publication(?string $from, string $to): self
    {
        return new self(sprintf(
            'A scholarship that is %s cannot be marked %s.',
            strtolower(OpportunityStatus::displayLabel($from)),
            strtolower(OpportunityStatus::displayLabel($to))
        ));
    }

    public static function moderation(?string $from, string $to): self
    {
        return new self(sprintf(
            'A scholarship that is %s cannot be moved to %s.',
            strtolower(OpportunityModerationStatus::displayLabel($from)),
            strtolower(OpportunityModerationStatus::displayLabel($to))
        ));
    }

    public static function withdrawn(): self
    {
        return new self('This scholarship has been withdrawn and can no longer be changed.');
    }
}
