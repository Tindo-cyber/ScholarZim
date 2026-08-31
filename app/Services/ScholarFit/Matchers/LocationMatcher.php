<?php

namespace App\Services\ScholarFit\Matchers;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\DimensionResult;
use App\Services\ScholarFit\Taxonomy\Locality;

/**
 * Geographic compatibility, scored as a hierarchy rather than a single guess.
 *
 * Country, province, district and locality are each worth a stated share of the
 * location weight, and each is only assessed when the listing actually targets
 * it. Narrower targeting therefore earns more when it matches, and a listing
 * that names nothing geographic cannot quietly collect most of the weight the
 * way v1's 53%-for-silence branch did.
 *
 * Every tier is additive on top of country, so the tiers must sum to 1.0 for a
 * fully-matched, fully-targeted listing to reach full marks - that is asserted
 * in the tests rather than left as an assumption about config.
 */
final class LocationMatcher
{
    public function match(ApplicantProfile $profile, Opportunity $opportunity, int $weight): DimensionResult
    {
        $tiers = config('scholarfit.location');
        $credit = config('scholarfit.credit');

        $targetCountry = filled($opportunity->target_country)
            ? $opportunity->target_country
            : $opportunity->country;

        // Nothing geographic stated at all: unknown, so a half mark.
        if (blank($targetCountry)
            && blank($opportunity->required_province)
            && blank($opportunity->target_district)
            && blank($opportunity->target_locality)) {
            return DimensionResult::make(
                'location',
                'Location',
                (float) $credit['neutral'],
                $weight,
                'This listing does not target a particular location'
            );
        }

        if (blank($profile->country) && filled($targetCountry)) {
            return DimensionResult::make(
                'location',
                'Location',
                0.0,
                $weight,
                'No country on your profile',
                'Add your country on your profile',
                DimensionResult::TARGET_PROFILE,
                'country'
            );
        }

        $ratio = 0.0;
        $matched = [];

        if (filled($targetCountry)) {
            if (strcasecmp(trim((string) $profile->country), trim($targetCountry)) !== 0) {
                // The country is the outermost ring; missing it means the rest
                // cannot rescue the dimension.
                return DimensionResult::make(
                    'location',
                    'Location',
                    0.0,
                    $weight,
                    'Targets ' . $targetCountry . '; your profile shows ' . $profile->country
                );
            }

            $ratio += (float) $tiers['country'];
            $matched[] = $targetCountry;
        }

        $ratio += $this->tier(
            $opportunity->required_province,
            $profile->province,
            (float) $tiers['province'],
            $matched
        );

        $ratio += $this->tier(
            $opportunity->target_district,
            $profile->district,
            (float) $tiers['district'],
            $matched
        );

        $ratio += $this->localityTier($opportunity, $profile, (float) $tiers['locality'], $matched);

        // A listing that targets only a province, with no country named, would
        // otherwise be capped at its province tier alone. Matching everything a
        // listing asked for is a full match by definition.
        $ratio = $this->normaliseAgainstTargeting($opportunity, $ratio, $tiers);

        return DimensionResult::make(
            'location',
            'Location',
            $ratio,
            $weight,
            $matched === []
                ? 'Your location does not match what this listing targets'
                : 'Matches on ' . implode(', ', $matched)
        );
    }

    /**
     * One narrowing tier. Only scored when the listing targets it and the
     * profile can answer - an unanswered tier earns nothing rather than
     * defaulting to a match.
     */
    private function tier(?string $target, ?string $held, float $share, array &$matched): float
    {
        if (blank($target) || blank($held)) {
            return 0.0;
        }

        if (strcasecmp(trim($held), trim($target)) !== 0) {
            return 0.0;
        }

        $matched[] = $target;

        return $share;
    }

    private function localityTier(
        Opportunity $opportunity,
        ApplicantProfile $profile,
        float $share,
        array &$matched
    ): float {
        $target = Locality::canonical($opportunity->target_locality);
        $held = Locality::canonical($profile->locality);

        if ($target === null || $held === null || $target !== $held) {
            return 0.0;
        }

        $matched[] = Locality::label($target) . ' applicants';

        return $share;
    }

    /**
     * Scales the earned ratio by the most this listing could have awarded.
     *
     * Without it, targeting is punished for being specific in the wrong
     * direction: an award open to all of Zimbabwe tops out at the country tier
     * and would score 0.6 for a perfect match, while one that also names a
     * province could reach 0.85. Both applicants matched everything asked of
     * them, so both should read as a full location match.
     */
    private function normaliseAgainstTargeting(Opportunity $opportunity, float $ratio, array $tiers): float
    {
        $available = 0.0;

        if (filled($opportunity->target_country) || filled($opportunity->country)) {
            $available += (float) $tiers['country'];
        }
        if (filled($opportunity->required_province)) {
            $available += (float) $tiers['province'];
        }
        if (filled($opportunity->target_district)) {
            $available += (float) $tiers['district'];
        }
        if (Locality::canonical($opportunity->target_locality) !== null) {
            $available += (float) $tiers['locality'];
        }

        return $available > 0.0 ? $ratio / $available : $ratio;
    }
}
