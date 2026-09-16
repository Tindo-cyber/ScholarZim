<?php

namespace App\Services;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            // A degree classification is the tertiary equivalent of a grade,
            // held once on the profile rather than as a subject result - which
            // is what keeps a First Class out of any A-Level points total.
            // Cleared when the applicant moves to a level it cannot describe.
            'degree_classification' => \App\Support\EducationLevel::usesTranscript($data['education_level'] ?? null)
                ? ($data['degree_classification'] ?? null)
                : null,
            'province' => $data['province'] ?? null,
            'locality' => $data['locality'] ?? null,
            'settlement_type' => $data['settlement_type'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? null,
            // Guardian fields are collected only for the Primary pathway;
            // cleared otherwise so a profile that moves off Primary does not
            // carry on displaying a guardian section it no longer needs.
            'guardian_name' => $isPrimary ? ($data['guardian_name'] ?? null) : null,
            'guardian_phone' => $isPrimary ? ($data['guardian_phone'] ?? null) : null,
            'guardian_relationship' => $isPrimary ? ($data['guardian_relationship'] ?? null) : null,
            'guardian_confirmed_at' => $isPrimary && filled($data['guardian_confirmed'] ?? null)
                ? Carbon::now()
                : ($isPrimary ? $profile->guardian_confirmed_at : null),
            // `academic_results`, the legacy free-text column, is deliberately
            // absent. It is no longer written, no longer read by ScholarFit and
            // no longer part of profile completeness; writing it here would
            // also have overwritten every existing production value with null
            // the moment the textarea left the form.
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
     * Brings the applicant's structured results into line with a submission.
     *
     * Call this only when academic results were actually part of the request -
     * ProfileController gates on an explicit marker. The method it replaces
     * was called unconditionally on every profile save with an array that was
     * always empty, and opened by deleting every result the applicant had, so
     * editing a phone number silently destroyed an academic record and changed
     * the applicant's eligibility with it.
     *
     * Rows are upserted on (profile_id, qualification_id, subject_id), the same
     * key the table enforces, and only rows the submission dropped are removed.
     * Nothing here trusts the request: the qualification must be active, the
     * subject must belong to it, and the grade must be one that qualification
     * actually awards. Points are derived from the qualification's own scheme
     * and are never read from the request - an applicant states facts, the
     * platform does the arithmetic.
     *
     * @param  array<int, array{qualification_id: int|string, subject_id: int|string, result: string, year?: int|string|null}>  $rows
     *
     * @throws ValidationException when a row is not a fact this catalogue recognises
     */
    public function syncAcademicResults(User $user, array $rows): ApplicantProfile
    {
        $profile = $this->forUser($user);

        // Resolved once rather than per row: the allowed set is the same for
        // every row in a submission, and asking the profile for it each time
        // would re-query the catalogue up to forty times for one save.
        $allowedQualificationIds = $profile->selectableQualifications()
            ->map(fn (AcademicQualification $q) => (int) $q->id)
            ->all();

        DB::transaction(function () use ($profile, $rows, $user, $allowedQualificationIds) {
            $keptIds = [];
            $seen = [];

            foreach ($rows as $index => $row) {
                [$qualification, $subject, $grade] = $this->resolveResultRow(
                    $profile,
                    $allowedQualificationIds,
                    $row,
                    $index
                );

                // Belt and braces alongside the unique key: a duplicate pair in
                // one submission would otherwise upsert the same row twice and
                // quietly keep only the last grade the applicant typed.
                $pair = $qualification->id.':'.$subject->id;

                if (isset($seen[$pair])) {
                    throw ValidationException::withMessages([
                        "academic_subject_results.$index.subject_id" => $subject->name.' is listed more than once under '
                            .$qualification->name.'. Record each subject once.',
                    ]);
                }

                $seen[$pair] = true;

                $result = AcademicResult::updateOrCreate(
                    [
                        'profile_id' => $profile->profile_id,
                        'qualification_id' => $qualification->id,
                        'subject_id' => $subject->id,
                    ],
                    [
                        'result' => $grade,
                        'year' => $this->resultYear($row['year'] ?? null),
                        // Read from the subject, not the qualification: a
                        // subject may be awarded on a scale of its own.
                        'derived_points' => $subject->pointsFor($grade),
                    ]
                );

                $keptIds[] = $result->id;
            }

            // Only what the applicant removed. An empty submission from a form
            // that did carry the academic section means they deleted every row,
            // which is a legitimate thing to have done.
            $profile->academicResults()->whereNotIn('id', $keptIds)->delete();

            $this->auditService->log(
                $user->email,
                AuditAction::PROFILE_UPDATE,
                'APPLICANT_PROFILE',
                $profile->profile_id,
                'Saved '.count($keptIds).' academic result(s)'
            );
        });

        return $profile->refresh();
    }

    /**
     * Resolves one submitted row against the catalogue, or refuses it.
     *
     * @return array{0: AcademicQualification, 1: AcademicSubject, 2: string}
     *
     * @throws ValidationException
     */
    /** @param  array<int, int>  $allowedQualificationIds */
    private function resolveResultRow(
        ApplicantProfile $profile,
        array $allowedQualificationIds,
        array $row,
        int|string $index,
    ): array {
        $qualification = AcademicQualification::find($row['qualification_id'] ?? null);

        if ($qualification === null || ! $qualification->is_active) {
            throw ValidationException::withMessages([
                "academic_subject_results.$index.qualification_id" => 'Choose a qualification from the list.',
            ]);
        }

        // The form only offers qualifications at or below the applicant's own
        // level, and the server holds the same line: a Primary applicant
        // posting an O-Level result is stating something they cannot have.
        if (! in_array((int) $qualification->id, $allowedQualificationIds, true)) {
            throw ValidationException::withMessages([
                "academic_subject_results.$index.qualification_id" => $qualification->name
                    .' is above your current education level ('
                    .\App\Support\EducationLevel::label($profile->education_level)
                    .'). Update your education level first if you have sat it.',
            ]);
        }

        $subject = AcademicSubject::find($row['subject_id'] ?? null);

        // A subject belongs to exactly one qualification. Cambridge IGCSE
        // Mathematics and ZIMSEC A-Level Mathematics are different rows sat
        // under different boards, and a request that pairs one board's subject
        // with another's qualification is not a fact about anybody.
        if ($subject === null || (int) $subject->qualification_id !== (int) $qualification->id) {
            throw ValidationException::withMessages([
                "academic_subject_results.$index.subject_id" => 'Choose a subject offered under '.$qualification->name.'.',
            ]);
        }

        if (! $subject->is_active) {
            throw ValidationException::withMessages([
                "academic_subject_results.$index.subject_id" => $subject->name.' is no longer offered under '.$qualification->name.'.',
            ]);
        }

        // Validated against the subject's own scale where it has one, so a
        // Cambridge IGCSE 9-1 syllabus accepts 9..1 and an A*-G syllabus under
        // the very same qualification does not. Stored in that scale's own
        // casing, so a Cambridge AS "a" stays lower case.
        $grade = $subject->canonicalGrade($row['result'] ?? null);

        if ($grade === null) {
            $awardedBy = $subject->hasOwnScheme() ? $subject->name : $qualification->name;

            throw ValidationException::withMessages([
                "academic_subject_results.$index.result" => 'Choose a result that '.$awardedBy.' awards ('
                    .implode(', ', $subject->grades()).').',
            ]);
        }

        return [$qualification, $subject, $grade];
    }

    private function resultYear(mixed $year): ?int
    {
        if (blank($year)) {
            return null;
        }

        $year = (int) $year;
        $ceiling = (int) Carbon::now()->year + 1;

        return $year >= 1950 && $year <= $ceiling ? $year : null;
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
