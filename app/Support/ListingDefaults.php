<?php

namespace App\Support;

use App\Models\Opportunity;
use App\Models\User;

/**
 * What a new listing form starts with, so a provider posting their fourth
 * bursary does not retype the currency and the link they have used every time.
 *
 * Taken from their most recent listing and their profile, never from anyone
 * else's, and only for the three things that are genuinely repeated:
 *
 *   - the currency they quote awards in
 *   - the province they usually restrict to
 *   - their website, for the application link - but only a CONFIRMED one. An
 *     address that has merely been typed in is not yet known to be theirs, and
 *     pre-filling it would put it in front of the risk checker as a link that
 *     then fails the check.
 *
 * These are starting values in a form the provider reads and submits, not rules
 * applied behind their back: the province, which is an eligibility rule, makes
 * the stricter-rules section open so it is seen rather than sneaked in.
 */
final class ListingDefaults
{
    private function __construct()
    {
    }

    /** @return array<string, mixed> only the keys that have a value to offer */
    public static function forProvider(User $provider): array
    {
        $defaults = [];

        $last = Opportunity::query()
            ->where('provider_user_id', $provider->user_id)
            // A draft is unfinished and may be wrong; defaults come from what was actually submitted.
            ->notDraft()
            ->orderByDesc('opportunity_id')
            ->first();

        if ($last !== null) {
            if (filled($last->award_currency)) {
                $defaults['award_currency'] = $last->award_currency;
            }

            if (filled($last->required_province)) {
                $defaults['required_province'] = $last->required_province;
            }
        }

        $profile = $provider->providerProfile;

        if ($profile !== null && $profile->hasConfirmedWebsite()) {
            $defaults['external_url'] = $profile->website;
        }

        return $defaults;
    }
}
