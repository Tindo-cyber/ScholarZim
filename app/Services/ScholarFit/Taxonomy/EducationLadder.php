<?php

namespace App\Services\ScholarFit\Taxonomy;

/**
 * Education levels as an ordered ladder rather than a set of strings.
 *
 * v1 kept a four-entry map of "related" levels, keyed on 'Undergraduate',
 * 'Honours', 'Masters' and 'PhD'. The profile dropdown offers 'Honours Degree',
 * so that key never matched anything a user could pick, and the map had no entry
 * at all for 'Certificate', 'Diploma', 'Postgraduate' or either High School
 * level - which between them cover most Zimbabwean applicants.
 *
 * Ordering the levels instead means closeness is measured rather than
 * enumerated: adjacent rungs are a near miss, two rungs apart is a stretch, and
 * anything further apart is a different audience. Adding a level is one array
 * entry rather than a fresh set of pairings.
 */
final class EducationLadder
{
    /**
     * Rungs in ascending order. The index is the level; the strings are the
     * spellings that land on it, normalised on comparison.
     */
    private const RUNGS = [
        ['primary', 'general primary'],
        ['high school o level', 'o level', 'olevel', 'ordinary level', 'form 4', 'secondary'],
        ['high school a level', 'a level', 'alevel', 'advanced level', 'form 6'],
        ['certificate'],
        ['diploma', 'national diploma'],
        ['undergraduate', 'bachelor', 'bachelors', 'bachelor degree', 'degree', 'bsc', 'ba'],
        ['honours degree', 'honours', 'honors', 'hons', 'bachelor honours'],
        ['postgraduate', 'postgraduate diploma', 'pgd'],
        ['masters', 'master', 'master degree', 'msc', 'ma', 'mba'],
        ['phd', 'doctorate', 'doctoral', 'dphil'],
    ];

    private function __construct()
    {
    }

    /** The rung a written level sits on, or null when nothing recognises it. */
    public static function rung(?string $level): ?int
    {
        $normalised = self::normalise($level);

        if ($normalised === null) {
            return null;
        }

        foreach (self::RUNGS as $index => $spellings) {
            foreach ($spellings as $spelling) {
                if (self::normalise($spelling) === $normalised) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * How many rungs apart two levels are, or null when either is unrecognised.
     * Direction is deliberately discarded: a provider asking for a Masters is
     * equally mismatched by an undergraduate and by a PhD candidate, and which
     * of those is the bigger problem is a judgement the score should not make.
     */
    public static function distance(?string $a, ?string $b): ?int
    {
        $left = self::rung($a);
        $right = self::rung($b);

        if ($left === null || $right === null) {
            return null;
        }

        return abs($left - $right);
    }

    private static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strtolower(trim($value));
        $clean = str_replace(['(', ')', '-', '/', '&', ',', '.', '—'], ' ', $clean);
        $clean = (string) preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);

        return $clean === '' ? null : $clean;
    }
}
