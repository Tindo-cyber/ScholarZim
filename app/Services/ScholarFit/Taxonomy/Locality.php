<?php

namespace App\Services\ScholarFit\Taxonomy;

/**
 * Rural or urban - a property of where someone lives, not of which province.
 *
 * Kept as its own concept so the location hierarchy stays orthogonal: a student
 * in Masvingo may be rural or urban, and both facts are worth knowing
 * separately. v1 folded this into the province field, which made the two
 * mutually exclusive and the rule unreachable.
 */
final class Locality
{
    public const RURAL = 'RURAL';

    public const URBAN = 'URBAN';

    public const ALL = [self::RURAL, self::URBAN];

    private function __construct()
    {
    }

    /** The stored form of a written locality, or null when it is neither. */
    public static function canonical(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'rural', 'communal', 'resettlement' => self::RURAL,
            'urban', 'city', 'town' => self::URBAN,
            default => null,
        };
    }

    public static function label(?string $value): string
    {
        return match (self::canonical($value)) {
            self::RURAL => 'Rural',
            self::URBAN => 'Urban',
            default => 'Unspecified',
        };
    }
}
