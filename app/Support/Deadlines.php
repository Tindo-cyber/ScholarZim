<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * "Today" for an application deadline.
 *
 * A deadline is a calendar date in Zimbabwe, so what counts as today is Harare's date. The application timezone stays UTC
 * on purpose (config/app.php: stored timestamps were written under UTC), which makes Carbon::today() a different date
 * from Harare's between midnight and 02:00 there. Anything deciding whether a new deadline is still ahead asks here.
 */
final class Deadlines
{
    public const TIMEZONE = 'Africa/Harare';

    private function __construct()
    {
    }

    /** Harare's date today, as a date at the start of the day. */
    public static function today(): Carbon
    {
        return Carbon::parse(Carbon::now(self::TIMEZONE)->toDateString());
    }

    /** The earliest deadline a new or changed listing may have: tomorrow, Harare time. */
    public static function earliest(): Carbon
    {
        return self::today()->addDay();
    }
}
