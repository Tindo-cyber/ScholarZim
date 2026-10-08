<?php

namespace App\Services\Catalogue;

use App\Models\LegacyFieldAlias;
use App\Models\Programme;
use Illuminate\Database\QueryException;

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
        // Civil engineering is filed under 073 (building and civil engineering) in ISCED-F, not 071.
        'Engineering' => ['071', '073'],
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

    /**
     * The catalogue field codes an old value means: the built-in map first, then any alias an
     * administrator has made. Empty when nothing knows it - and for a school label, on purpose.
     *
     * @return array<int, string>
     */
    public static function codesFor(?string $value): array
    {
        $wanted = Programme::normalise($value);

        if ($wanted === '') {
            return [];
        }

        foreach (self::MAP as $old => $codes) {
            if (Programme::normalise($old) === $wanted) {
                return $codes;
            }
        }

        try {
            $alias = LegacyFieldAlias::with('field')->where('value_key', $wanted)->first();
        } catch (QueryException) {
            return [];
        }

        return $alias?->field ? [$alias->field->code] : [];
    }

    /** "General Primary" and "General Secondary": levels, not subjects. They map to nothing on purpose. */
    public static function isSchoolLabel(?string $value): bool
    {
        $wanted = Programme::normalise($value);

        foreach (self::MAP as $old => $codes) {
            if ($codes === [] && Programme::normalise($old) === $wanted) {
                return true;
            }
        }

        return false;
    }
}
