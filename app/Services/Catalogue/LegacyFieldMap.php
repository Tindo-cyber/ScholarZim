<?php

namespace App\Services\Catalogue;

use App\Models\Programme;

/**
 * The sixteen fields of study the old forms offered, mapped onto the ISCED-F catalogue.
 *
 * Needed to read a listing's wording against the catalogue ("a bursary for Law students"), and
 * again when the old free-text values on profiles and listings are moved onto the catalogue.
 * Each maps to the NARROW field(s) it means, never a broad one: a name must not open a scope
 * wider than it says. Two of the sixteen are school labels with no programme field.
 *
 * This is a decision about meaning, so it is data to be checked, not code to be trusted.
 */
final class LegacyFieldMap
{
    /** @var array<string, array<int, string>> old value => narrow field codes */
    public const MAP = [
        'Computer Science & IT' => ['061'],
        'Engineering' => ['071'],
        'Medicine & Health Sciences' => ['091'],
        'Law' => ['042'],
        'Business & Finance' => ['041'],
        'Education' => ['011'],
        'Agriculture & Agribusiness' => ['081'],
        'Arts & Humanities' => ['021', '022', '023'],
        'Natural Sciences' => ['051', '053', '054'],
        'Social Sciences' => ['031'],
        'Nursing' => ['091'],
        'Accounting' => ['041'],
        'Environmental Science' => ['052'],
        'Mining & Metallurgy' => ['072'],
        'General Primary' => [],
        'General Secondary' => [],
    ];

    private function __construct()
    {
    }

    /** @return array<int, string> narrow field codes for an old value (any case, "&" or "and"), or none */
    public static function codesFor(?string $value): array
    {
        $wanted = Programme::normalise($value);

        foreach (self::MAP as $old => $codes) {
            if (Programme::normalise($old) === $wanted) {
                return $codes;
            }
        }

        return [];
    }
}
