<?php

namespace App\Services;

use App\Support\AwardSanity;

/**
 * Reasons a moderator should look at a listing twice.
 *
 * This is the home of every such check. For now it holds the two that exist -
 * an award above its currency ceiling, and a listing posted on another
 * organisation's behalf - and Phase 3 adds the description and contact checks
 * here rather than somewhere new.
 *
 * It only reports. A flag never blocks a save and never decides anything: it
 * is stored on the listing and shown to the administrator reviewing it, who
 * decides. Each reason is a {code, message}: the code is for code (filtering,
 * tests), the message is written for the moderator.
 */
class ListingRiskChecker
{
    public const AWARD_ABOVE_CEILING = 'award_above_ceiling';

    public const ON_BEHALF_OF = 'on_behalf_of';

    /**
     * @param  array<string, mixed>  $listing  the attributes about to be saved
     * @return array<int, array{code: string, message: string}>
     */
    public function check(array $listing): array
    {
        $flags = [];

        $amount = filled($listing['award_amount'] ?? null) ? (float) $listing['award_amount'] : null;

        if ($reason = AwardSanity::flagReason($amount, $listing['award_currency'] ?? null)) {
            $flags[] = ['code' => self::AWARD_ABOVE_CEILING, 'message' => $reason];
        }

        if (filled($listing['on_behalf_of'] ?? null)) {
            $flags[] = [
                'code' => self::ON_BEHALF_OF,
                'message' => 'Posted on behalf of "' . $listing['on_behalf_of']
                    . '". Check the provider is authorised to award in that name.',
            ];
        }

        return $flags;
    }
}
