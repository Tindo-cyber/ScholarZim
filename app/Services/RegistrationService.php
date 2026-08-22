<?php

namespace App\Services;

use App\Models\ApplicantProfile;
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\RoleNames;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegistrationService
{
    public function __construct(
        private readonly EmailVerificationService $emailVerificationService,
        private readonly NotificationService $notificationService,
        private readonly AuditService $auditService,
        private readonly FileStorageService $fileStorage,
    ) {
    }

    /** Students are active immediately; only their email needs verifying. */
    public function registerApplicant(array $data): User
    {
        $role = Role::where('role_name', RoleNames::APPLICANT)->firstOrFail();

        $user = DB::transaction(function () use ($data, $role) {
            $user = User::create([
                'role_id' => $role->role_id,
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password_hash' => Hash::make($data['password']),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => false,
            ]);

            ApplicantProfile::create(['user_id' => $user->user_id]);

            return $user;
        });

        $this->auditService->log($user->email, AuditAction::REGISTER, 'USER', $user->user_id, 'Student registration');
        $this->emailVerificationService->issue($user);

        return $user;
    }

    /**
     * Providers land in PENDING and cannot publish until an admin has checked
     * their registration certificate.
     */
    public function registerProvider(array $data, UploadedFile $certificate): User
    {
        $role = Role::where('role_name', RoleNames::PROVIDER)->firstOrFail();
        $certificatePath = $this->fileStorage->store($certificate, 'provider-certificates');

        $user = DB::transaction(function () use ($data, $role, $certificate, $certificatePath) {
            $user = User::create([
                'role_id' => $role->role_id,
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password_hash' => Hash::make($data['password']),
                'account_status' => AccountStatus::PENDING,
                'email_verified' => false,
            ]);

            ProviderProfile::create([
                'user_id' => $user->user_id,
                'organisation_type' => $data['organisation_type'],
                'registration_number' => $data['registration_number'],
                'certificate_path' => $certificatePath,
                'certificate_filename' => $certificate->getClientOriginalName(),
                'submitted_at' => Carbon::now(),
            ]);

            return $user;
        });

        $this->auditService->log($user->email, AuditAction::REGISTER, 'USER', $user->user_id, 'Provider registration');
        $this->emailVerificationService->issue($user);
        $this->notifyAdminsOfPendingProvider($user);

        return $user;
    }

    private function notifyAdminsOfPendingProvider(User $provider): void
    {
        $admins = User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->get();

        $this->notificationService->notifyMany(
            $admins,
            NotificationType::PROVIDER_APPLICATION,
            'New provider awaiting verification: ' . $provider->displayName() . '.',
            '/admin/dashboard#provider-verification',
            $provider->user_id
        );
    }
}
