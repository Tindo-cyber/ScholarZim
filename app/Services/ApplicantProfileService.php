<?php

namespace App\Services;

use App\Models\ApplicantProfile;
use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        $isPrimary = \App\Support\EducationLevel::isPrimary($data['education_level'] ?? null);

        $profile->update([
            'education_level' => $data['education_level'] ?? null,
            'institution_name' => $data['institution_name'] ?? null,
            // Field of study and year of study are university-tier concepts;
            // stored as null rather than whatever was left in the form when an
            // applicant switches to a level neither applies to, so a profile
            // never carries a "field of study" that no longer means anything
            // for its own education level.
            'field_of_study' => \App\Support\EducationLevel::usesFieldOfStudy($data['education_level'] ?? null)
                ? ($data['field_of_study'] ?? null)
                : null,
            'year_of_study' => \App\Support\EducationLevel::usesFieldOfStudy($data['education_level'] ?? null)
                ? ($data['year_of_study'] ?? null)
                : null,
            'province' => $data['province'] ?? null,
            'locality' => $data['locality'] ?? null,
            'settlement_type' => $data['settlement_type'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            // Guardian fields are collected only for the Primary pathway;
            // cleared otherwise so a profile that moves off Primary does not
            // carry on displaying a guardian section it no longer needs.
            'guardian_name' => $isPrimary ? ($data['guardian_name'] ?? null) : null,
            'guardian_phone' => $isPrimary ? ($data['guardian_phone'] ?? null) : null,
            'guardian_relationship' => $isPrimary ? ($data['guardian_relationship'] ?? null) : null,
            'guardian_confirmed_at' => $isPrimary && filled($data['guardian_confirmed'] ?? null)
                ? Carbon::now()
                : ($isPrimary ? $profile->guardian_confirmed_at : null),
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
        $supersededPath = $profile->{$prefix . '_path'};

        $uploadedAtColumn = $prefix === 'results_certificate'
            ? 'results_uploaded_at'
            : $prefix . '_uploaded_at';

        // Renamed to the document type rather than kept as whatever the
        // student's device called it (e.g. "IMG_20240512.jpg").
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $renamedTo = ApplicantProfile::DOCUMENT_FILE_LABELS[$documentType] . ($extension ? '.' . $extension : '');

        // The replacement is written before anything is taken away. Deleting the
        // old file first - as this used to - meant a failed upload or a failed
        // update left the profile pointing at a path that no longer existed, so
        // a student lost the document they already had by trying to replace it.
        $storedPath = $this->fileStorage->store($file, 'profiles/' . $user->user_id, $user);

        try {
            DB::transaction(function () use ($profile, $prefix, $storedPath, $renamedTo, $uploadedAtColumn, $user, $documentType) {
                $profile->update([
                    $prefix . '_path' => $storedPath,
                    $prefix . '_filename' => $renamedTo,
                    $uploadedAtColumn => Carbon::now(),
                ]);

                $this->auditService->logOrFail(
                    $user->email,
                    AuditAction::PROFILE_UPDATE,
                    'APPLICANT_PROFILE',
                    $profile->profile_id,
                    'Uploaded ' . $documentType
                );
            });
        } catch (\Throwable $e) {
            // The row still refers to the previous file, so it is the new upload
            // that is now the orphan.
            $this->fileStorage->delete($storedPath);

            throw $e;
        }

        // Committed: the old file is genuinely unreferenced and safe to remove.
        if ($supersededPath !== $storedPath) {
            $this->fileStorage->delete($supersededPath);
        }

        return $profile;
    }
}
