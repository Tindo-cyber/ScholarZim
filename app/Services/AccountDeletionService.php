<?php

namespace App\Services;

use App\Models\Application;
use App\Models\EmailVerificationToken;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\PasswordResetToken;
use App\Models\SavedScholarship;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removing an account and everything hanging off it.
 *
 * The foreign keys in this schema do not cascade - deliberately, so a stray
 * delete cannot quietly take applications with it - which means the dependent
 * rows have to come out here, in order, inside one transaction.
 *
 * Two things are refused rather than forced:
 *
 *   A provider still holding live listings. Other people's applications point at
 *   those rows, and deleting the listing would erase their history too. They
 *   withdraw the listings first.
 *
 *   The bootstrap super admin, which nothing may remove.
 *
 * The audit trail is written before the delete and is deliberately not removed
 * with the account: it records what the platform did, not who the user was, and
 * it is what a later dispute is settled from.
 */
class AccountDeletionService
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    /**
     * @param  User  $user  the account to remove
     * @param  string  $actorEmail  who asked - the owner, or the admin acting
     *
     * @throws RuntimeException when the account cannot be removed as it stands
     */
    public function delete(User $user, string $actorEmail, bool $selfService = false): void
    {
        if ($user->is_super_admin) {
            throw new RuntimeException('The super admin account cannot be deleted.');
        }

        $liveListings = Opportunity::where('provider_user_id', $user->user_id)
            ->whereIn('moderation_status', ['PENDING', 'APPROVED'])
            ->count();

        if ($liveListings > 0) {
            throw new RuntimeException(
                'This account still has ' . $liveListings . ' scholarship listing(s). '
                    . 'Withdraw them before deleting the account, so applicants keep their history.'
            );
        }

        $email = $user->email;
        $userId = $user->user_id;

        // Logged before the delete: afterwards there is no account to attribute
        // the entry to.
        $this->auditService->log(
            $actorEmail,
            $selfService ? AuditAction::ACCOUNT_SELF_DELETED : AuditAction::DELETE_USER,
            'USER',
            $userId,
            ($selfService ? 'Deleted their own account' : 'Deleted account') . ' ' . $email
        );

        DB::transaction(function () use ($user, $userId) {
            Notification::where('user_id', $userId)->delete();
            SavedScholarship::where('user_id', $userId)->delete();
            SavedSearch::where('user_id', $userId)->delete();
            Application::where('user_id', $userId)->delete();
            EmailVerificationToken::where('user_id', $userId)->delete();
            PasswordResetToken::where('user_id', $userId)->delete();

            // Withdrawn or rejected listings have no applications pointing at
            // them by this stage, so they can go with the account.
            Opportunity::where('provider_user_id', $userId)->delete();

            $user->applicantProfile()->delete();
            $user->providerProfile()->delete();
            $user->tokens()->delete();

            $user->delete();
        });
    }
}
