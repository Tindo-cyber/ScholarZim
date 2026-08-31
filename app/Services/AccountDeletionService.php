<?php

namespace App\Services;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\EmailVerificationToken;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\PasswordResetToken;
use App\Models\SavedScholarship;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
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
    public function __construct(
        private readonly AuditService $auditService,
        private readonly FileStorageService $fileStorage,
    ) {
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

        // A listing blocks deletion unless the provider withdrew it or an
        // administrator declined it. Asked across both axes now that withdrawal
        // lives on the publication column: checking moderation alone would have
        // counted a withdrawn listing as live, because withdrawing no longer
        // overwrites the approval it used to erase.
        $liveListings = Opportunity::where('provider_user_id', $user->user_id)
            ->where('status', '!=', OpportunityStatus::WITHDRAWN)
            ->where('moderation_status', '!=', OpportunityModerationStatus::REJECTED)
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

        // Collected before the transaction, because the rows that point at these
        // files are about to be deleted and the paths would go with them.
        $documentPaths = $this->documentPathsFor($user);

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

        // Files last, and only once the account is genuinely gone. Deleting them
        // first would destroy a student's documents on the way to a deletion that
        // might still fail and roll back, leaving an account whose paperwork had
        // already been shredded.
        //
        // Deleting the rows used to be the whole of it, which left every upload -
        // national IDs, passports, results certificates - sitting in storage
        // belonging to an account that no longer existed, with nothing left
        // referring to them and so nothing that would ever find them again.
        $removed = 0;

        foreach ($documentPaths as $path) {
            if ($this->fileStorage->exists($path)) {
                $this->fileStorage->delete($path);
                $removed++;
            }
        }

        // Anything recorded against them that the column sweep above missed.
        $removed += $this->fileStorage->deleteAllForUser($user);

        $this->auditService->log(
            $actorEmail,
            AuditAction::DOCUMENTS_PURGED,
            'USER',
            $userId,
            'Removed ' . $removed . ' uploaded file(s) belonging to ' . $email
        );
    }

    /**
     * Every stored path this account owns, gathered from the columns that hold
     * them.
     *
     * Read from the owning rows rather than only from document_files, because
     * uploads that predate the metadata table have no record there - and a
     * deletion that only removed the files it had paperwork for would quietly
     * leave the oldest documents behind.
     *
     * @return array<int, string>
     */
    private function documentPathsFor(User $user): array
    {
        $paths = [];

        $profile = $user->applicantProfile;

        if ($profile !== null) {
            foreach (ApplicantProfile::DOCUMENT_TYPES as $prefix) {
                $paths[] = $profile->{$prefix . '_path'};
            }
        }

        $paths[] = $user->providerProfile?->certificate_path;

        foreach (Application::where('user_id', $user->user_id)->pluck('document_path') as $path) {
            $paths[] = $path;
        }

        return array_values(array_filter(array_unique($paths), static fn ($p) => filled($p)));
    }
}
