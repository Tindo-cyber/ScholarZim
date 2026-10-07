<?php

namespace App\Support;

/** Where a student report of a listing stands. */
final class ReportStatus
{
    /** Filed, and not yet looked at by an administrator. Only these count towards hiding a listing. */
    public const PENDING = 'PENDING';

    /** An administrator agreed. This is what takes a provider's trust away. */
    public const UPHELD = 'UPHELD';

    /** An administrator disagreed. It no longer counts towards anything. */
    public const DISMISSED = 'DISMISSED';

    private function __construct()
    {
    }
}
