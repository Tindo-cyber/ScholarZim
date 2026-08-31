<?php

namespace App\Services;

use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\RoleNames;
use Illuminate\Support\Carbon;
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

    public function approveProvider(int $userId, User $admin): User
    {
        $user = User::with('providerProfile')->findOrFail($userId);

        $user->update(['account_status' => AccountStatus::ACTIVE]);
        $user->providerProfile?->update([
            'reviewed_at' => Carbon::now(),
            'reviewed_by' => $admin->email,
            'rejection_reason' => null,
        ]);

        $this->auditService->log(
            $admin->email,
            AuditAction::APPROVE_PROVIDER,
            'USER',
            $user->user_id,
            'Approved provider ' . $user->email
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
        $user = User::with('providerProfile')->findOrFail($userId);

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
            'Rejected provider ' . $user->email . ': ' . $reason
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
}
