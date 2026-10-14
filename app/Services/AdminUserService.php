<?php

namespace App\Services;

use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\AuditAction;
use App\Support\Countries;
use App\Support\NotificationType;
use App\Support\ProviderRegistrationDetails;
use App\Support\RoleNames;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly AuditService $auditService,
        private readonly EmailService $emailService,
        private readonly AccountDeletionService $accountDeletion,
    ) {
    }

    public function paginate(array $filters = [], int $perPage = 20)
    {
        $query = User::with('role')->orderByDesc('user_id');

        if (filled($filters['role'] ?? null)) {
            $query->whereHas('role', fn ($q) => $q->where('role_name', $filters['role']));
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('account_status', $filters['status']);
        }

        if (filled($filters['q'] ?? null)) {
            $term = '%' . $filters['q'] . '%';
            $query->where(fn ($q) => $q->where('full_name', 'like', $term)->orWhere('email', 'like', $term));
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /** Providers waiting on verification, oldest submission first. */
    public function pendingProviders()
    {
        return ProviderProfile::with('user')
            ->whereNull('reviewed_at')
            ->orderBy('submitted_at')
            ->get();
    }

    public function pendingProviderCount(): int
    {
        return ProviderProfile::whereNull('reviewed_at')->count();
    }

    public function createUser(array $data, User $admin): User
    {
        $role = Role::where('role_name', $data['role_name'])->firstOrFail();

        $user = User::create([
            'role_id' => $role->role_id,
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password_hash' => Hash::make($data['password']),
            'account_status' => AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);

        $this->auditService->log(
            $admin->email,
            AuditAction::ADMIN_CREATED_USER,
            'USER',
            $user->user_id,
            'Created ' . RoleNames::displayLabel($role->role_name) . ' account ' . $user->email
        );

        $this->emailService->sendWelcome($user);

        return $user;
    }

    public function approveProvider(int $userId, User $admin, bool $websiteChecked = false): User
    {
        $user = $this->requireProvider($userId);

        // Verifying an organisation means checking its document against where it
        // says it is registered. An account from before that was asked has no
        // country on record; it is recorded first (from the review page), never
        // assumed. Any country is acceptable - only an unknown one is not.
        if ($user->providerProfile !== null && ! $user->providerProfile->hasRegistrationCountry()) {
            throw new RuntimeException('Record the country ' . $user->displayName() . ' is registered in before verifying it.');
        }

        $user->update(['account_status' => AccountStatus::ACTIVE]);
        $user->providerProfile?->update([
            'reviewed_at' => Carbon::now(),
            'reviewed_by' => $admin->email,
            'rejection_reason' => null,
        ]);

        // Verifying the organisation is the natural moment to check its website too;
        // ticking the box confirms it in the same step.
        if ($websiteChecked) {
            app(ProviderWebsiteService::class)->confirmDuringApproval($admin, $user);
        }

        $this->auditService->log(
            $admin->email,
            AuditAction::APPROVE_PROVIDER,
            'USER',
            $user->user_id,
            'Approved provider ' . $user->email . $this->countryNote($user)
        );

        $this->notificationService->notifyUser(
            $user,
            NotificationType::PROVIDER_APPROVED,
            'Your provider account has been approved. You can now publish scholarships.',
            '/provider/dashboard',
            $user->user_id
        );

        return $user;
    }

    public function rejectProvider(int $userId, User $admin, string $reason): User
    {
        $user = $this->requireProvider($userId);

        $user->update(['account_status' => AccountStatus::REJECTED]);
        $user->providerProfile?->update([
            'reviewed_at' => Carbon::now(),
            'reviewed_by' => $admin->email,
            'rejection_reason' => $reason,
        ]);

        $this->auditService->log(
            $admin->email,
            AuditAction::REJECT_PROVIDER,
            'USER',
            $user->user_id,
            'Rejected provider ' . $user->email . $this->countryNote($user) . ': ' . $reason
        );

        $this->notificationService->notifyUser(
            $user,
            NotificationType::PROVIDER_REJECTED,
            'Your provider account was not approved: ' . $reason,
            '/account/security',
            $user->user_id
        );

        return $user;
    }

    /**
     * Asking an organisation for more evidence before deciding. The account stays
     * PENDING and cannot publish; the organisation sees the note on its dashboard,
     * answers by updating its details or document, and returns to the queue.
     */
    public function requestProviderInformation(int $userId, User $admin, string $note): User
    {
        $user = $this->requireProvider($userId);
        $profile = $user->providerProfile;

        if ($profile === null || $profile->isReviewed()) {
            throw new RuntimeException('More information can only be requested from an organisation that is still awaiting verification.');
        }

        $profile->update([
            'info_requested_at' => Carbon::now(),
            'info_requested_by' => $admin->email,
            'info_request_note' => $note,
        ]);

        $this->auditService->log(
            $admin->email,
            AuditAction::REQUEST_PROVIDER_INFO,
            'USER',
            $user->user_id,
            'Requested more information from provider ' . $user->email . $this->countryNote($user) . ': ' . $note
        );

        $this->notificationService->notifyUser(
            $user,
            NotificationType::PROVIDER_INFO_REQUESTED,
            'We need more information to verify your organisation: ' . $note,
            '/provider/dashboard#registration-details',
            $user->user_id
        );

        return $user;
    }

    /**
     * An administrator recording or correcting an organisation's registration
     * details - chiefly the country of an account registered before it was asked.
     * Changes nothing about the account's status, listings or applications.
     */
    public function updateProviderRegistration(int $userId, User $admin, array $data): User
    {
        $user = $this->requireProvider($userId);
        $profile = $user->providerProfile;

        if ($profile === null) {
            throw new RuntimeException('This provider has no organisation profile to update.');
        }

        $new = ProviderRegistrationDetails::attributesFrom($data);
        $old = $profile->only(array_keys($new));

        if ($old == $new) {
            return $user;
        }

        DB::transaction(function () use ($admin, $user, $profile, $old, $new) {
            $profile->update($new);

            $this->auditService->logOrFail(
                $admin->email,
                AuditAction::UPDATE_PROVIDER_REGISTRATION,
                'USER',
                $user->user_id,
                'Updated registration details for ' . $user->email . ': registered in '
                    . (Countries::name($new['registration_country']) ?? 'not recorded'),
                ['old' => $old, 'new' => $new]
            );
        });

        return $user->fresh('providerProfile');
    }

    public function suspend(int $userId, User $admin): User
    {
        $user = $this->requireNotSuperAdmin($userId);

        // Suspension now ends the session it is applied to, so suspending
        // yourself logs you straight out - and if you were the only active
        // administrator, nobody is left who can undo it.
        if ($user->user_id === $admin->user_id) {
            throw new RuntimeException('You cannot suspend your own account.');
        }

        $user->update(['account_status' => AccountStatus::SUSPENDED]);

        $this->auditService->log($admin->email, AuditAction::UPDATE_USER, 'USER', $user->user_id, 'Suspended account');

        return $user;
    }

    public function reactivate(int $userId, User $admin): User
    {
        $user = User::findOrFail($userId);
        $user->update(['account_status' => AccountStatus::ACTIVE]);

        $this->auditService->log($admin->email, AuditAction::UPDATE_USER, 'USER', $user->user_id, 'Reactivated account');

        return $user;
    }

    /**
     * Deletion goes through AccountDeletionService so an admin removing an
     * account and a user removing their own take exactly the same path: the same
     * dependent rows come out, in the same order, under the same refusals.
     */
    public function delete(int $userId, User $admin): void
    {
        $user = $this->requireNotSuperAdmin($userId);

        if ($user->user_id === $admin->user_id) {
            throw new RuntimeException('You cannot delete your own account.');
        }

        $this->accountDeletion->delete($user, $admin->email);
    }

    /** The bootstrap super admin cannot be locked out or removed by another admin. */
    private function requireNotSuperAdmin(int $userId): User
    {
        $user = User::findOrFail($userId);

        if ($user->is_super_admin) {
            throw new RuntimeException('The super admin account cannot be modified.');
        }

        return $user;
    }

    /**
     * Provider verification only ever means something for a Provider
     * account. The UI never links these routes against any other kind of
     * user - the dashboard's pending-provider list is built from
     * ProviderProfile rows, which only exist for provider registrations -
     * but the id in the URL is still an admin-supplied request value, and a
     * wrong one must not be able to flip an Applicant's or Administrator's
     * own account_status and send them a "your provider account..."
     * notification for an account that was never a provider's.
     */
    private function requireProvider(int $userId): User
    {
        $user = User::with('providerProfile')->findOrFail($userId);

        if ($user->roleName() !== RoleNames::PROVIDER) {
            throw new RuntimeException('Only a provider account can be verified this way.');
        }

        return $user;
    }

    /** " (registered in Kenya)", so the audit trail names the country a decision was made on. */
    private function countryNote(User $user): string
    {
        $country = $user->providerProfile?->registrationCountryName();

        return ' (registered in ' . ($country ?? 'a country not recorded') . ')';
    }

    /** One organisation's full record, for its review page. */
    public function providerForReview(int $userId): User
    {
        $user = User::with('providerProfile', 'role')->findOrFail($userId);

        if ($user->roleName() !== RoleNames::PROVIDER || $user->providerProfile === null) {
            abort(404);
        }

        return $user;
    }
}
