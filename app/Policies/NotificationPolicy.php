<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/**
 * A notification belongs to exactly one recipient.
 *
 * Reading someone else's would leak the thing it is about - that they applied
 * somewhere, were accepted, or were rejected - so the addressee is
 * the only party, with no administrative override.
 */
class NotificationPolicy
{
    public function view(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->user_id;
    }

    public function markRead(User $user, Notification $notification): bool
    {
        return $this->view($user, $notification);
    }
}
