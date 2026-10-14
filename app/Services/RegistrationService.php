<?php

namespace App\Services;

use App\Models\ApplicantProfile;
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\AuditAction;
use App\Support\Countries;
use App\Support\NotificationType;
use App\Support\ProviderRegistrationDetails;
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
        // Stored before the transaction because a filesystem cannot be rolled
        // back with it - so if the registration fails, the certificate is
        // deleted again rather than left on disk belonging to no account.
        $certificatePath = $this->fileStorage->store($certificate, 'provider-certificates');

        try {
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
                    'website' => $data['website'] ?? null,
                    'contact_name' => $data['contact_name'] ?? null,
                    'contact_role' => $data['contact_role'] ?? null,
                ] + ProviderRegistrationDetails::attributesFrom($data) + [
                    'certificate_path' => $certificatePath,
                    'certificate_filename' => $certificate->getClientOriginalName(),
                    'submitted_at' => Carbon::now(),
                ]);

                return $user;
            });
        } catch (\Throwable $e) {
            $this->fileStorage->delete($certificatePath);

            throw $e;
        }

        $this->auditService->log(
            $user->email,
            AuditAction::REGISTER,
            'USER',
            $user->user_id,
            'Provider registration (registered in ' . (Countries::name($data['registration_country'] ?? null) ?? 'a country not recorded') . ')'
        );
        $this->emailVerificationService->issue($user);
        $this->notifyAdminsOfPendingProvider($user, $data['registration_country'] ?? null);

        return $user;
    }

    /**
     * An organisation still awaiting verification updating what it registered
     * with - typically in answer to an administrator's request for more
     * information. Only possible before a decision: once verified, details that
     * were checked are not changed by the organisation alone, and a rejected one
     * cannot reopen its own review. The account stays PENDING; this puts the
     * organisation back in front of an administrator, it does not approve it.
     */
    public function updatePendingRegistration(User $provider, array $data, ?UploadedFile $document = null): ProviderProfile
    {
        // Decided on what is stored now, not on a model loaded earlier: an
        // administrator may have approved or rejected in the meantime.
        $provider = $provider->fresh('providerProfile');
        $profile = $provider?->providerProfile;

        if ($provider === null || $profile === null || $profile->isReviewed()
            || strcasecmp((string) $provider->account_status, AccountStatus::PENDING) !== 0) {
            throw new \RuntimeException('Your organisation has already been reviewed, so its registration details can no longer be changed here.');
        }

        $newPath = $document ? $this->fileStorage->store($document, 'provider-certificates', $provider) : null;
        $oldPath = $profile->certificate_path;

        try {
            DB::transaction(function () use ($data, $profile, $document, $newPath) {
                $profile->update([
                    'contact_name' => $data['contact_name'],
                    'contact_role' => $data['contact_role'],
                    'info_provided_at' => Carbon::now(),
                ] + ProviderRegistrationDetails::attributesFrom($data) + ($newPath ? [
                    'certificate_path' => $newPath,
                    'certificate_filename' => $document->getClientOriginalName(),
                ] : []));
            });
        } catch (\Throwable $e) {
            $this->fileStorage->delete($newPath);

            throw $e;
        }

        // The replaced document goes only once the new one is on record.
        if ($newPath && $oldPath && $oldPath !== $newPath) {
            $this->fileStorage->delete($oldPath);
        }

        $this->auditService->log(
            $provider->email,
            AuditAction::UPDATE_PROVIDER_REGISTRATION,
            'USER',
            $provider->user_id,
            'Provider updated its registration details (registered in ' . Countries::name($data['registration_country']) . ')'
                . ($newPath ? ' and replaced its registration document' : '')
        );

        $admins = User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->get();
        $this->notificationService->notifyMany(
            $admins,
            NotificationType::PROVIDER_APPLICATION,
            $provider->displayName() . ' updated its registration details and is ready for review.',
            '/admin/providers/' . $provider->user_id,
            $provider->user_id
        );

        return $profile->fresh();
    }

    private function notifyAdminsOfPendingProvider(User $provider, ?string $country): void
    {
        $admins = User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->get();

        $this->notificationService->notifyMany(
            $admins,
            NotificationType::PROVIDER_APPLICATION,
            'New provider awaiting verification: ' . $provider->displayName() . ' (registered in ' . (Countries::name($country) ?? 'a country not recorded') . ').',
            '/admin/providers/' . $provider->user_id,
            $provider->user_id
        );
    }
}
