<?php

namespace App\Policies;

use App\Models\SavedScholarship;
use App\Models\User;

/**
 * A saved list is private to the student who built it.
 *
 * There is no shared or provider-visible case: a provider learning which
 * students had bookmarked their award would be a disclosure the student never
 * agreed to, so ownership is the entire rule.
 */
class SavedScholarshipPolicy
{
    public function view(User $user, SavedScholarship $saved): bool
    {
        return $this->owns($user, $saved);
    }

    public function delete(User $user, SavedScholarship $saved): bool
    {
        return $this->owns($user, $saved);
    }

    /** Only students keep a saved list at all. */
    public function create(User $user): bool
    {
        return $user->isApplicant();
    }

    private function owns(User $user, SavedScholarship $saved): bool
    {
        return $saved->user_id === $user->user_id;
    }
}
