<?php

namespace App\Services;

use App\Models\HeldBackScholarship;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A scholarship an applicant has deliberately set aside, kept separate from
 * SavedScholarshipService: a save is a watchlist entry, a hold-back is an
 * applicant saying "not this one, for now" about something ScholarFit
 * recommended. Both can be true of the same listing at once.
 */
class HeldBackScholarshipService
{
    public function listHeldBack(User $user)
    {
        return HeldBackScholarship::with('opportunity.provider')
            ->where('user_id', $user->user_id)
            ->orderByDesc('held_back_at')
            ->get();
    }

    public function hold(User $user, int $opportunityId): HeldBackScholarship
    {
        // Fails loudly if the listing is not public, so a held-back list can
        // never hold something the applicant is not allowed to see.
        Opportunity::query()->publiclyVisible()->findOrFail($opportunityId);

        return HeldBackScholarship::firstOrCreate(
            ['user_id' => $user->user_id, 'opportunity_id' => $opportunityId],
            ['held_back_at' => Carbon::now()]
        );
    }

    public function release(User $user, int $opportunityId): void
    {
        HeldBackScholarship::where('user_id', $user->user_id)
            ->where('opportunity_id', $opportunityId)
            ->delete();
    }

    public function isHeldBack(?User $user, int $opportunityId): bool
    {
        if (! $user) {
            return false;
        }

        return HeldBackScholarship::where('user_id', $user->user_id)
            ->where('opportunity_id', $opportunityId)
            ->exists();
    }

    /** @return array<int, int> opportunity ids, for excluding held-back listings from active Matches */
    public function heldBackIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return HeldBackScholarship::where('user_id', $user->user_id)
            ->pluck('opportunity_id')
            ->all();
    }

    public function count(User $user): int
    {
        return HeldBackScholarship::where('user_id', $user->user_id)->count();
    }
}
