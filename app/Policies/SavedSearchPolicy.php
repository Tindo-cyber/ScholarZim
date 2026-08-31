<?php

namespace App\Policies;

use App\Models\SavedSearch;
use App\Models\User;

/**
 * A saved search is private to its owner, and so are its alerts.
 *
 * Toggling alerts is called out separately from updating because it is the one
 * that sends mail: someone able to flip another student's alerts could use the
 * platform to send them messages they never asked for.
 */
class SavedSearchPolicy
{
    public function view(User $user, SavedSearch $search): bool
    {
        return $this->owns($user, $search);
    }

    public function delete(User $user, SavedSearch $search): bool
    {
        return $this->owns($user, $search);
    }

    public function toggleAlerts(User $user, SavedSearch $search): bool
    {
        return $this->owns($user, $search);
    }

    public function create(User $user): bool
    {
        return $user->isApplicant();
    }

    private function owns(User $user, SavedSearch $search): bool
    {
        return $search->user_id === $user->user_id;
    }
}
