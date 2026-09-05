<?php

namespace App\Services\ScholarFit\Taxonomy;

/**
 * Rural or urban - a property of where someone lives, not of which province,
 * and not the same thing as `ApplicantProfile::locality` (which is now a
 * specific place name, e.g. "Gweru").
 *
 * Named `SettlementType` rather than `Locality` because the education/location
 * domain redesign gave "Locality" a different, more specific meaning - a named
 * place a scholarship can target directly - and this taxonomy was the thing
 * sitting on that name first. Renaming this rather than the new concept keeps
 * "Locality" meaning what everyone filling in a form would expect it to mean.
 *
 * Kept as its own concept so the location hierarchy stays orthogonal: a
 * student in Masvingo may be rural or urban, and both facts are worth knowing
 * separately from which specific place they are in.
 */
final class SettlementType
{
    public const RURAL = 'RURAL';

    public const URBAN = 'URBAN';

    public const ALL = [self::RURAL, self::URBAN];

    private function __construct()
    {
    }

    /** The stored form of a written settlement type, or null when it is neither. */
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
