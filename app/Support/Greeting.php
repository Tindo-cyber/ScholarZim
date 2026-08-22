<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class Greeting
{
    private function __construct()
    {
    }

    public static function forNow(): string
    {
        $hour = (int) Carbon::now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /** "Good morning, Tendai" — falls back to the whole name when there is no space. */
    public static function forUser(?string $fullName): string
    {
        $greeting = self::forNow();
        $name = trim((string) $fullName);

        if ($name === '') {
            return $greeting;
        }

        $first = explode(' ', $name)[0];

        return $greeting . ', ' . $first;
    }
}
