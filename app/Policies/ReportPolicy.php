<?php

namespace App\Policies;

use App\Models\User;
use App\Support\RoleNames;

/**
 * Platform-wide reporting is administrative.
 *
 * These exports cross every account on the platform - applicants, providers and
 * their applications - so they are the one place where "administrators retain
 * legitimate administrative access" is the whole rule. A provider's own figures
 * are a different screen, served by ProviderAnalyticsService and scoped to
 * their listings.
 */
class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function export(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function viewAuditLog(User $user): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $user->roleName() === RoleNames::ADMIN;
    }
}
