<?php

namespace App\Services\ScholarFit\Taxonomy;

/**
 * Fields of study, reduced to a handful of canonical concepts.
 *
 * v1 compared the two strings directly and kept a five-entry "related" map
 * beside it. That map was keyed on "Computer Science", "Medicine" and
 * "Information Technology" - none of which a ScholarZim user can actually
 * select, because the dropdown offers "Computer Science & IT" and "Medicine &
 * Health Sciences". Three of its five keys were unreachable, so in practice the
 * only thing that ever matched was an exact string, and a student who typed
 * "CS" scored zero against a Computer Science bursary.
 *
 * The categories below are intentionally few. The point is not to describe
 * academia, it is to answer one question - are these two people talking about
 * the same subject? - across the spellings Zimbabwean applicants and providers
 * actually use. Anything unrecognised keeps its own normalised text and simply
 * matches only itself, which is no worse than v1 managed.
 */
final class FieldTaxonomy
{
    /**
     * Canonical category => the spellings that mean it.
     *
     * The dropdown value is always included so FormOptions::FIELDS_OF_STUDY maps
     * cleanly, alongside the abbreviations and free-text variants that reach us
     * from older profiles and provider-entered listings.
     */
    private const CATEGORIES = [
        'COMPUTING' => [
            'computer science & it', 'computer science', 'computer sciences', 'cs',
            'computing', 'information technology', 'it', 'software engineering',
            'data science', 'informatics',
        ],
        'ENGINEERING' => [
            'engineering', 'mechanical engineering', 'civil engineering',
            'electrical engineering', 'chemical engineering', 'eng',
        ],
        'HEALTH' => [
            'medicine & health sciences', 'medicine', 'health sciences', 'nursing',
            'pharmacy', 'public health', 'medical', 'dentistry',
        ],
        'LAW' => ['law', 'legal studies', 'llb'],
        'BUSINESS' => [
            'business & finance', 'business', 'business administration', 'finance',
            'economics', 'accounting', 'accountancy', 'commerce', 'management',
        ],
        'EDUCATION' => ['education', 'teaching', 'teacher training'],
        'AGRICULTURE' => [
            'agriculture & agribusiness', 'agriculture', 'agribusiness',
            'agricultural science', 'animal science', 'horticulture',
        ],
        'SCIENCES' => [
            'natural sciences', 'environmental science', 'science', 'physics',
            'chemistry', 'biology', 'mathematics', 'maths', 'statistics',
        ],
        'MINING' => ['mining & metallurgy', 'mining', 'metallurgy', 'geology'],
        'ARTS' => [
            'arts & humanities', 'arts', 'humanities', 'social sciences',
            'sociology', 'history', 'languages', 'psychology',
        ],
        'GENERAL' => ['general primary', 'general secondary', 'general studies'],
    ];

    /**
     * Families of neighbouring categories, for partial credit.
     *
     * Deliberately sparse: only pairs where a provider would plausibly consider
     * the other subject, not everything that shares a faculty building. Listed
     * once and read both ways, so relatedness cannot be accidentally
     * one-directional the way v1's map was.
     */
    private const RELATED = [
        ['COMPUTING', 'ENGINEERING'],
        ['COMPUTING', 'SCIENCES'],
        ['ENGINEERING', 'MINING'],
        ['ENGINEERING', 'SCIENCES'],
        ['HEALTH', 'SCIENCES'],
        ['AGRICULTURE', 'SCIENCES'],
        ['BUSINESS', 'LAW'],
        ['ARTS', 'EDUCATION'],
        ['ARTS', 'LAW'],
    ];

    private function __construct()
    {
    }

    /**
     * The canonical category for a written field, or the normalised text itself
     * when nothing recognises it.
     */
    public static function canonical(?string $field): ?string
    {
        $normalised = self::normalise($field);

        if ($normalised === null) {
            return null;
        }

        // Aliases are normalised on the way in rather than stored pre-folded, so
        // the list above stays readable as the spellings people actually write.
        foreach (self::CATEGORIES as $category => $aliases) {
            foreach ($aliases as $alias) {
                if (self::normalise($alias) === $normalised) {
                    return $category;
                }
            }
        }

        return $normalised;
    }

    /** Whether two written fields mean the same subject. */
    public static function sameCategory(?string $a, ?string $b): bool
    {
        $left = self::canonical($a);

        return $left !== null && $left === self::canonical($b);
    }

    /** Whether two written fields sit in neighbouring categories. */
    public static function related(?string $a, ?string $b): bool
    {
        $left = self::canonical($a);
        $right = self::canonical($b);

        if ($left === null || $right === null || $left === $right) {
            return false;
        }

        foreach (self::RELATED as [$one, $other]) {
            if (($left === $one && $right === $other) || ($left === $other && $right === $one)) {
                return true;
            }
        }

        return false;
    }

    /** A human label for a canonical category, for the explanation lines. */
    public static function label(?string $field): string
    {
        $canonical = self::canonical($field);

        if ($canonical === null) {
            return 'Unspecified';
        }

        return match ($canonical) {
            'COMPUTING' => 'Computing & IT',
            'ENGINEERING' => 'Engineering',
            'HEALTH' => 'Health sciences',
            'LAW' => 'Law',
            'BUSINESS' => 'Business & finance',
            'EDUCATION' => 'Education',
            'AGRICULTURE' => 'Agriculture',
            'SCIENCES' => 'Natural sciences',
            'MINING' => 'Mining & metallurgy',
            'ARTS' => 'Arts & humanities',
            'GENERAL' => 'General studies',
            default => ucfirst($canonical),
        };
    }

    /** Lowercased, collapsed whitespace, punctuation folded to a space. */
    private static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strtolower(trim($value));
        $clean = str_replace(['&', '/', '-', ',', '.'], ' ', $clean);
        $clean = (string) preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);

        return $clean === '' ? null : $clean;
    }
}
