<?php

namespace App\Services;

use App\Models\ApplicantProfile;
use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ApplicantProfileService
{
    public function __construct(
        private readonly FileStorageService $fileStorage,
        private readonly AuditService $auditService,
    ) {
    }

    /** Every applicant has a profile row; it is created lazily on first visit. */
    public function forUser(User $user): ApplicantProfile
    {
        return ApplicantProfile::firstOrCreate(['user_id' => $user->user_id]);
    }

    public function update(User $user, array $data): ApplicantProfile
    {
        $profile = $this->forUser($user);

        $profile->update([
            'education_level' => $data['education_level'] ?? null,
            'institution_name' => $data['institution_name'] ?? null,
            'field_of_study' => $data['field_of_study'] ?? null,
            'country' => $data['country'] ?? null,
            'province' => $data['province'] ?? null,
            'academic_results' => $data['academic_results'] ?? null,
            'biography' => $data['biography'] ?? null,
        ]);

        if (filled($data['full_name'] ?? null) || filled($data['phone'] ?? null)) {
            $user->update(array_filter([
                'full_name' => $data['full_name'] ?? null,
                'phone' => $data['phone'] ?? null,
            ], static fn ($v) => $v !== null));
        }

        $this->auditService->log($user->email, AuditAction::PROFILE_UPDATE, 'APPLICANT_PROFILE', $profile->profile_id);

        return $profile;
    }

    /**
     * Replaces one of the four supported profile documents, deleting whatever
     * was there before so orphaned uploads do not accumulate.
     */
    public function storeDocument(User $user, string $documentType, UploadedFile $file): ApplicantProfile
    {
        $prefix = ApplicantProfile::DOCUMENT_TYPES[$documentType] ?? null;

        if ($prefix === null) {
            throw new InvalidArgumentException('Unknown document type: ' . $documentType);
        }

        $profile = $this->forUser($user);

        $this->fileStorage->delete($profile->{$prefix . '_path'});

        $uploadedAtColumn = $prefix === 'results_certificate'
            ? 'results_uploaded_at'
            : $prefix . '_uploaded_at';

        // Renamed to the document type rather than kept as whatever the
        // student's device called it (e.g. "IMG_20240512.jpg").
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $renamedTo = ApplicantProfile::DOCUMENT_FILE_LABELS[$documentType] . ($extension ? '.' . $extension : '');

        $profile->update([
            $prefix . '_path' => $this->fileStorage->store($file, 'profiles/' . $user->user_id),
            $prefix . '_filename' => $renamedTo,
            $uploadedAtColumn => Carbon::now(),
        ]);

        $this->auditService->log(
            $user->email,
            AuditAction::PROFILE_UPDATE,
            'APPLICANT_PROFILE',
            $profile->profile_id,
            'Uploaded ' . $documentType
        );

        return $profile;
    }
}
