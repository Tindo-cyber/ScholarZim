<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an applicant's profile is missing information ScholarFit
 * depends on to evaluate a listing's requirements at all.
 *
 * Kept as its own type, distinct from a failed eligibility check (a plain
 * RuntimeException from EligibilityEvaluator::unmetReasons()), so a
 * controller can tell "finish your profile first" apart from "you don't
 * meet this scholarship's requirements" and point the applicant at the
 * right fix - the profile form for one, nothing for the other, since a
 * refused eligibility check is not something filling in a field repairs.
 *
 * Extends RuntimeException on purpose, the same reasoning
 * InvalidApplicationTransition documents: every applicant-facing controller
 * already treats a RuntimeException as "business rule said no, tell the
 * user", so this is reported correctly even by a caller that only catches
 * the parent type.
 */
class ProfileIncompleteException extends RuntimeException
{
    /** @param array<int, string> $missingFields */
    public function __construct(public readonly array $missingFields)
    {
        parent::__construct(
            'Please complete your profile before applying: ' . implode(', ', $missingFields) . '.'
        );
    }
}
