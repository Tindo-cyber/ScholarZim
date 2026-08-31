<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\RoleNames;

/**
 * Who may manage, and who may moderate, a scholarship listing.
 *
 * The two are kept apart on purpose, and the separation is the point of this
 * policy: a provider owns their listing and may edit or withdraw it, but may
 * never publish it - that is an administrator's decision, and it is the whole
 * reason the moderation queue exists. Collapsing the two would let anyone who
 * can post also approve, which is the trust boundary the platform is built on.
 */
class OpportunityPolicy
{
    /** Anyone may read a listing that is actually on the public site. */
    public function view(?User $user, Opportunity $opportunity): bool
    {
        if ($opportunity->isPubliclyVisible()) {
            return true;
        }

        // Off the public site, it is visible to its owner and to administrators
        // reviewing it - and to nobody else, so an unapproved listing cannot be
        // read by guessing its id.
        return $user !== null && ($this->owns($user, $opportunity) || $this->isAdmin($user));
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $this->owns($user, $opportunity) && ! $opportunity->isWithdrawn();
    }

    public function withdraw(User $user, Opportunity $opportunity): bool
    {
        return $this->owns($user, $opportunity) && ! $opportunity->isWithdrawn();
    }

    public function extendDeadline(User $user, Opportunity $opportunity): bool
    {
        return $this->update($user, $opportunity);
    }

    /**
     * Moderation is an administrator's, and never the owner's.
     *
     * The ownership half of this is defensive rather than currently reachable:
     * a user holds one role, so an administrator cannot also be the provider who
     * posted a listing today. It is stated anyway because "the person who
     * approves it must not be the person who posted it" is the rule, and a rule
     * that lives only in the shape of the role table stops holding the moment
     * somebody adds a second role.
     */
    public function moderate(User $user, Opportunity $opportunity): bool
    {
        return $this->isAdmin($user) && ! $this->owns($user, $opportunity);
    }

    /** Providers post; administrators do not, and suspended accounts cannot. */
    public function create(User $user): bool
    {
        return $user->isProvider() && $user->isActive();
    }

    /** The applications received against this listing. */
    public function viewApplications(User $user, Opportunity $opportunity): bool
    {
        return $this->owns($user, $opportunity);
    }

    private function owns(User $user, Opportunity $opportunity): bool
    {
        return $user->isProvider() && $opportunity->provider_user_id === $user->user_id;
    }

    private function isAdmin(User $user): bool
    {
        return $user->roleName() === RoleNames::ADMIN;
    }
}
