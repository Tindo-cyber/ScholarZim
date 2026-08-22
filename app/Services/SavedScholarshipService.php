<?php

namespace App\Services;

use App\Models\Opportunity;
use App\Models\SavedScholarship;
use App\Models\User;
use Illuminate\Support\Carbon;

class SavedScholarshipService
{
    public function listSaved(User $user)
    {
        return SavedScholarship::with('opportunity.provider')
            ->where('user_id', $user->user_id)
            ->orderByDesc('saved_at')
            ->get();
    }

    public function save(User $user, int $opportunityId): SavedScholarship
    {
        // Fails loudly if the listing is not public, so a saved list can never
        // hold something the applicant is not allowed to see.
        Opportunity::query()->publiclyVisible()->findOrFail($opportunityId);

        return SavedScholarship::firstOrCreate(
            ['user_id' => $user->user_id, 'opportunity_id' => $opportunityId],
            ['saved_at' => Carbon::now()]
        );
    }

    public function remove(User $user, int $opportunityId): void
    {
        SavedScholarship::where('user_id', $user->user_id)
            ->where('opportunity_id', $opportunityId)
            ->delete();
    }

    public function isSaved(?User $user, int $opportunityId): bool
    {
        if (! $user) {
            return false;
        }

        return SavedScholarship::where('user_id', $user->user_id)
            ->where('opportunity_id', $opportunityId)
            ->exists();
    }

    /** @return array<int, int> opportunity ids, for highlighting cards in lists */
    public function savedIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return SavedScholarship::where('user_id', $user->user_id)
            ->pluck('opportunity_id')
            ->all();
    }

    public function count(User $user): int
    {
        return SavedScholarship::where('user_id', $user->user_id)->count();
    }
}
