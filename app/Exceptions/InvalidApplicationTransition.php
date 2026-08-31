<?php

namespace App\Exceptions;

use App\Support\ApplicationStatus;
use RuntimeException;

/**
 * A refused application status change, phrased for the person who attempted it
 * rather than for a log file.
 *
 * Extends RuntimeException on purpose: the applicant-facing controllers already
 * treat a RuntimeException as "business rule said no, tell the user", so every
 * caller reports this correctly without being taught about a new exception type.
 */
class InvalidApplicationTransition extends RuntimeException
{
    /** The move itself is not part of the lifecycle. */
    public static function between(?string $from, string $to): self
    {
        return new self(sprintf(
            'An application that is %s cannot be moved to %s.',
            self::describe($from),
            self::describe($to)
        ));
    }

    /**
     * The move exists, but not for this actor - a provider reaching for
     * WITHDRAWN, or an applicant reaching for a review status.
     */
    public static function notPermittedFor(string $actor, string $to): self
    {
        return new self(sprintf(
            'A %s may not set an application to %s.',
            $actor,
            self::describe($to)
        ));
    }

    /** Nothing may be done to a finished application. */
    public static function terminal(?string $from): self
    {
        return new self(sprintf(
            'This application was already %s, so its status can no longer be changed.',
            self::describe($from)
        ));
    }

    private static function describe(?string $status): string
    {
        return strtolower(ApplicationStatus::displayLabel($status));
    }
}
