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

        /**
         * $data[$key] when the request actually carried that key - including
         * an empty string, which is how a nullable text field is
         * intentionally cleared (Laravel's ConvertEmptyStringsToNull
         * middleware turns that into a present `null` before validation, so
         * "present" and "empty" are not the same test) - or the profile's
         * current value when the key is missing from the request entirely.
         *
         * This is the distinction a flat `$data[$key] ?? null` could not
         * make: "not submitted" and "submitted empty" both produced null,
         * so a field genuinely absent from a request - not one the
         * applicant actually emptied - was wiped exactly the same way. The
         * live profile form always posts every field, submitted or
         * cleared, so this never showed up through it; it matters the
         * moment any other caller posts a partial update.
         */
        $preserve = static fn (string $key) => array_key_exists($key, $data) ? $data[$key] : $profile->{$key};

        $newLevel = $preserve('education_level');
        // Guardian details belong to a minor, whatever their level; an adult's are cleared. Judged on the
        // profile as it will be after this save.
        $isMinor = (new ApplicantProfile(['date_of_birth' => $preserve('date_of_birth'), 'education_level' => $newLevel]))->isMinor();
        $isPrimary = \App\Support\EducationLevel::isPrimary($newLevel);
        $usesFieldOfStudy = \App\Support\EducationLevel::usesFieldOfStudy($newLevel);
        $usesTranscript = \App\Support\EducationLevel::usesTranscript($newLevel);

        $profile->update([
            'education_level' => $newLevel,
            // Posting the form states the level afresh, so it counts as confirming it.
            'education_level_confirmed_at' => array_key_exists('education_level', $data) ? now() : $profile->education_level_confirmed_at,
            'institution_name' => $preserve('institution_name'),
            // Field of study and degree classification are tier-specific
            // concepts, cleared outright - never merely preserved - the
            // moment the level no longer uses them: a genuine tier change
            // is a stronger, more specific signal than "this key happened
            // to be missing from one request", and always wins. A profile
            // never carries a field of study that no longer means anything
            // for its own education level.
            'field_of_study' => $usesFieldOfStudy ? $preserve('field_of_study') : null,
            // year_of_study is deliberately absent: no longer collected or
            // presented (see profile.blade.php), but not written to null
            // here either - an existing stored value is left exactly as it
            // is rather than being overwritten with a fact nobody asked for.
            // A degree classification is the tertiary equivalent of a grade,
            // held once on the profile rather than as a subject result - which
            // is what keeps a First Class out of any A-Level points total.
            // No longer asked for on the form (the documents are the evidence), but one already on file is kept: it still tells
            // ScholarFit that a degree is held.
            'degree_classification' => $usesTranscript ? $preserve('degree_classification') : null,
            'province' => $preserve('province'),
            'locality' => $preserve('locality'),
            'settlement_type' => $preserve('settlement_type'),
            'date_of_birth' => $preserve('date_of_birth'),
            'gender' => $preserve('gender'),
            // Guardian fields are collected only for the Primary pathway;
            // cleared outright otherwise, for the same reason field of study
            // is above - so a profile that moves off Primary does not carry
            // on displaying a guardian section it no longer needs.
            'guardian_name' => $isMinor ? $preserve('guardian_name') : null,
            'guardian_phone' => $isMinor ? $preserve('guardian_phone') : null,
            'guardian_relationship' => $isMinor ? $preserve('guardian_relationship') : null,
            // Ticked sets it (keeping the first time if it was already set); an explicit 0 clears it; a
            // caller that does not mention the key leaves it alone.
            'guardian_confirmed_at' => ! $isMinor ? null : (array_key_exists('guardian_confirmed', $data)
                ? (filter_var($data['guardian_confirmed'], FILTER_VALIDATE_BOOLEAN) ? ($profile->guardian_confirmed_at ?? Carbon::now()) : null)
                : $profile->guardian_confirmed_at),
            // `academic_results`, the legacy free-text column, is deliberately
            // absent. It is no longer written, no longer read by ScholarFit and
            // no longer part of profile completeness; writing it here would
            // also have overwritten every existing production value with null
            // the moment the textarea left the form.
            'biography' => $preserve('biography'),
        ]);

        // The name is required, so only a name that was given replaces it. The phone number is optional: a number that was
        // given replaces the old one, and one that was submitted EMPTY clears it - it used to be skipped as "nothing to
        // save", so the page said "Profile saved" and the old number came back. A request that does not carry the key at
        // all (a partial update from another form) leaves it alone.
        $account = [];

        if (filled($data['full_name'] ?? null)) {
            $account['full_name'] = $data['full_name'];
        }

        if (array_key_exists('phone', $data)) {
            $account['phone'] = filled($data['phone']) ? $data['phone'] : null;
        }

        if ($account !== []) {
            $user->update($account);
        }

        // The field of study is a programme chosen from the catalogue (Certificate and above). Judged on the profile as it
        // now stands, so a level changed in the same save decides which programmes are on offer; a programme left as it
        // was is not re-checked against a level the person has just moved on from.
        if (array_key_exists('current_programme_id', $data)) {
            $profile->refresh();
            $posted = filled($data['current_programme_id']) ? (int) $data['current_programme_id'] : null;

            if (\App\Services\Catalogue\ApplicantProgrammes::modeFor($profile) === \App\Services\Catalogue\ApplicantProgrammes::CURRENT
                && $posted !== $profile->current_programme_id) {
                app(\App\Services\Catalogue\ApplicantProgrammes::class)->setCurrent($profile, $user, $posted);
            }
        }

        if (array_key_exists('institution_name', $data)
            && \App\Services\Catalogue\ApplicantProgrammes::modeFor($profile->refresh()) === \App\Services\Catalogue\ApplicantProgrammes::CURRENT) {
            app(\App\Services\Catalogue\ApplicantProgrammes::class)->syncInstitution($profile);
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
            // level + subject name => the sittings already accepted in this submission, for the cross-board check below
            $sittings = [];

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

                // One qualification holds one result per subject (the table's own key; a re-sit replaces the grade). But
                // "Mathematics" sat under ZIMSEC A Level, Cambridge AS and Cambridge A Level is the same subject at the same
                // level, entered once for each board. That is a duplicate unless the rows are genuinely different sittings -
                // each with its own year - which is how a real re-sit with another board looks.
                $levelKey = (\App\Support\EducationLevel::canonical($qualification->education_level) ?? $qualification->education_level)
                    . ':' . $this->subjectKey($subject->name);
                $year = $this->resultYear($row['year'] ?? null);

                foreach ($sittings[$levelKey] ?? [] as $earlier) {
                    if ($year === null || $earlier['year'] === null || $year === $earlier['year']) {
                        throw ValidationException::withMessages([
                            "academic_subject_results.$index.subject_id" => $subject->name . ' is already recorded under '
                                . $earlier['qualification'] . '. Record each subject once - or, if these are different sittings, '
                                . 'give the year of each.',
                        ]);
                    }
                }

                $sittings[$levelKey][] = ['year' => $year, 'qualification' => $qualification->name];

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
     * What makes two subject names the same subject: the name without its bracketed syllabus note, in lower case.
     *
     * Boards name one subject differently - ZIMSEC's "Mathematics", Cambridge O Level's "Mathematics (Syllabus D)", IGCSE's
     * "Mathematics" and "Mathematics (9-1)" - and the bracket only says which syllabus or scale. "Additional Mathematics"
     * and "International Mathematics" are different subjects and keep their own keys.
     */
    private function subjectKey(string $name): string
    {
        $bare = (string) preg_replace('/\s*\([^)]*\)/u', '', $name);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $bare)));
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

        // The form only offers the results of the level the applicant has completed (and an
        // earlier level's only when a scholarship asks for them), and the server holds the same
        // line - see ApplicantProfile::selectableQualifications().
        if (! in_array((int) $qualification->id, $allowedQualificationIds, true)) {
            $above = \App\Services\ScholarFit\Taxonomy\EducationLadder::rung($qualification->education_level)
                > \App\Services\ScholarFit\Taxonomy\EducationLadder::rung($profile->education_level);

            throw ValidationException::withMessages([
                "academic_subject_results.$index.qualification_id" => $above
                    ? $qualification->name.' is above the highest level you have completed ('
                        .\App\Support\EducationLevel::label($profile->education_level)
                        .'). Update your level first if you have finished it.'
                    : $qualification->name.' results are not needed for your level ('
                        .\App\Support\EducationLevel::label($profile->education_level)
                        .'). Record the results of the level you have completed; earlier results are asked for only when a scholarship needs them.',
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
        $renamedTo = $profile->documentFileLabel($documentType) . ($extension ? '.' . $extension : '');

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
        // ...unless an application was submitted with it: that file is what its provider was sent, and stays until
        // no application refers to it (ApplicationDocument).
        if ($supersededPath !== $storedPath && ! \App\Models\ApplicationDocument::isReferenced($supersededPath)) {
            $this->fileStorage->delete($supersededPath);
        }

        return $profile;
    }
}
