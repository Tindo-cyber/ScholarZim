<?php

namespace App\Services;

use App\Exceptions\AwardsFilled;
use App\Exceptions\ProfileIncompleteException;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Support\ApplicationStateMachine;
use App\Support\ApplicationStatus;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The whole application workflow:
 *
 *     a student applies -> PENDING -> the provider accepts or rejects it with a
 *     written reason -> the student is notified -> done.
 *
 * There is no stage between acceptance and the scholarship being granted:
 * accepting is granting. Nothing further is asked of either side.
 */
class ApplicationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly AuditService $auditService,
        private readonly FileStorageService $fileStorage,
        private readonly ApplicantProfileService $profileService,
        private readonly EligibilityEvaluator $eligibilityEvaluator,
    ) {
    }

    public function forApplicant(User $user)
    {
        return Application::with('opportunity.provider')
            ->where('user_id', $user->user_id)
            ->orderByDesc('submitted_at')
            ->get();
    }

    public function paginateForApplicant(User $user, ?string $status = null, ?string $search = null, int $perPage = 10)
    {
        $query = Application::with('opportunity.provider')
            ->where('user_id', $user->user_id)
            ->orderByDesc('submitted_at');

        if (filled($status)) {
            $query->where('application_status', $status);
        }

        if (filled($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('application_id', 'like', '%' . $search . '%')
                    ->orWhereHas('opportunity', function ($opportunityQuery) use ($search) {
                        $opportunityQuery->where('title', 'like', '%' . $search . '%')
                            ->orWhere('provider_name', 'like', '%' . $search . '%')
                            ->orWhereHas('provider', function ($userQuery) use ($search) {
                                $userQuery->where('full_name', 'like', '%' . $search . '%')
                                    ->orWhere('email', 'like', '%' . $search . '%');
                            });
                    });
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function findForApplicant(int $applicationId, User $user): Application
    {
        $application = Application::with('opportunity.provider')->findOrFail($applicationId);

        abort_unless($application->user_id === $user->user_id, 403, 'Not your application.');

        return $application;
    }

    public function forProvider(User $provider, ?string $status = null, int $perPage = 15)
    {
        $query = Application::with(['opportunity', 'user.applicantProfile'])
            ->whereHas('opportunity', fn ($q) => $q->where('provider_user_id', $provider->user_id))
            ->orderByDesc('submitted_at');

        if (filled($status)) {
            $query->where('application_status', $status);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function findForProvider(int $applicationId, User $provider): Application
    {
        $application = Application::with(['opportunity', 'user.applicantProfile'])->findOrFail($applicationId);

        abort_unless(
            $application->opportunity?->provider_user_id === $provider->user_id,
            403,
            'You are not allowed to view this application.'
        );

        return $application;
    }

    /**
     * Records the first time a provider opens a still-pending application,
     * and tells the applicant once. application_status is never touched -
     * this is a timestamp, not a transition, so the state machine and
     * capacity logic are entirely unaffected. See the migration that added
     * the column, and ApplicationStatus::timeline(), which is where this
     * actually becomes visible: the existing "Under review" step, which
     * otherwise sits permanently undone on a pending application.
     *
     * The whereNull() guard on the UPDATE is what makes this safe to call on
     * every page load with no explicit lock: at most one call ever affects a
     * row, so two providers opening the same application at once - or one
     * provider refreshing the page twice - can never both fire the
     * notification. Called from ApplicationReviewController::show(), not
     * from review(): this is about looking, not deciding.
     */
    public function markViewedByProvider(Application $application, User $provider): void
    {
        if (ApplicationStatus::canonical($application->application_status) !== ApplicationStatus::PENDING) {
            return;
        }

        $now = Carbon::now();

        $updated = Application::whereKey($application->application_id)
            ->whereNull('viewed_by_provider_at')
            ->update(['viewed_by_provider_at' => $now]);

        if ($updated === 0) {
            return;
        }

        // So the page this request renders reflects it immediately, without
        // a reload.
        $application->viewed_by_provider_at = $now;

        $title = $application->opportunity?->title ?? 'a scholarship';

        $this->auditService->log(
            $provider->email,
            AuditAction::APPLICATION_VIEWED,
            'APPLICATION',
            $application->application_id,
            'Opened "' . $title . '" application from ' . ($application->user?->displayName() ?? 'a deleted user')
        );

        if ($application->user) {
            $this->notificationService->notifyUser(
                $application->user,
                NotificationType::APPLICATION_VIEWED,
                'Your application to "' . $title . '" is now being reviewed by the provider.',
                '/applications/' . $application->application_id . '/confirmation',
                $application->application_id
            );
        }
    }

    /** True if the applicant already has an application against this listing. */
    public function hasApplied(User $user, int $opportunityId): bool
    {
        return Application::where('user_id', $user->user_id)
            ->where('opportunity_id', $opportunityId)
            ->blockingReapplication()
            ->exists();
    }

    /**
     * The audit trail for an application, newest first.
     *
     * Returns entries from APPLY (the moment the application was created) through
     * STATUS_UPDATE (provider decisions) and WITHDRAW_APPLICATION (if withdrawn).
     * Each entry carries a human-readable label and the timestamp from the audit
     * row.
     *
     * @return array<int, array{date: \Illuminate\Support\Carbon, action: string, label: string, reason: ?string}>
     */
    public function history(int $applicationId, User $user): array
    {
        $application = $this->findForApplicant($applicationId, $user);

        $actions = [
            AuditAction::APPLY,
            AuditAction::STATUS_UPDATE,
            AuditAction::REOPEN_APPLICATION,
            AuditAction::WITHDRAW_APPLICATION,
        ];

        return AuditLog::where('entity_type', 'APPLICATION')
            ->where('entity_id', $application->application_id)
            ->whereIn('action', $actions)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (AuditLog $log) {
                $labels = [
                    AuditAction::APPLY => 'Your application was submitted',
                    AuditAction::STATUS_UPDATE => $this->statusUpdateLabel($log),
                    AuditAction::REOPEN_APPLICATION => $this->reopenLabel($log),
                    AuditAction::WITHDRAW_APPLICATION => 'You withdrew this application',
                ];

                return [
                    'date' => $log->created_at,
                    'action' => $log->action,
                    'label' => $labels[$log->action] ?? AuditAction::displayLabel($log->action),
                    'reason' => $log->reason,
                ];
            })
            ->all();
    }

    /** What the applicant is told happened when a provider withdrew a decision: without implying an acceptance still holds. */
    private function reopenLabel(AuditLog $log): string
    {
        return match (ApplicationStatus::canonical($log->old_values['application_status'] ?? null)) {
            ApplicationStatus::ACCEPTED => 'The provider withdrew their acceptance and reopened your application for review',
            ApplicationStatus::REJECTED => 'The provider withdrew their decision and reopened your application for review',
            default => 'The provider reopened your application for review',
        };
    }

    private function statusUpdateLabel(AuditLog $log): string
    {
        $newStatus = $log->new_values['application_status'] ?? null;

        if (blank($newStatus)) {
            return 'The provider updated your application';
        }

        $canonical = ApplicationStatus::canonical($newStatus);

        if ($canonical === ApplicationStatus::ACCEPTED) {
            return 'The provider accepted your application';
        }

        if ($canonical === ApplicationStatus::REJECTED) {
            return 'The provider declined your application';
        }

        return 'The provider updated your application to ' . ApplicationStatus::displayLabel($newStatus);
    }

    /**
     * Opportunity ids this applicant can no longer apply to.
     *
     * One student plus one scholarship is one application, so pending, accepted
     * and rejected all appear here. Only a withdrawn application leaves the
     * listing open again.
     *
     * @return array<int, int>
     */
    public function appliedIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return Application::where('user_id', $user->user_id)
            ->blockingReapplication()
            ->pluck('opportunity_id')
            ->all();
    }

    /**
     * This applicant's accepted applications, keyed by the listing they belong
     * to.
     *
     * A companion to appliedIds() for the listing pages: an acceptance is a
     * subset of "already applied", and the cards need to say which subset -
     * "Applied" on a scholarship a student has actually won reads as though
     * nothing has happened yet.
     *
     * @return array<int, Application>
     */
    public function acceptedByOpportunity(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return Application::where('user_id', $user->user_id)
            ->where('application_status', ApplicationStatus::ACCEPTED)
            ->get(['application_id', 'opportunity_id', 'decided_at'])
            ->keyBy('opportunity_id')
            ->all();
    }

    /**
     * One-click apply from a listing card. Uses whatever is already on the
     * applicant's profile instead of asking for a statement.
     */
    public function quickApply(int $opportunityId, User $user): Application
    {
        return $this->submit($opportunityId, $user, [
            'personal_statement' => null,
        ], null);
    }

    /**
     * Full submission from the wizard.
     *
     * Guards, in order: the listing must have cleared moderation, still be open,
     * still be inside its deadline, and the applicant must not already have an
     * application against it.
     *
     * A withdrawn application reuses its row rather than inserting a new one,
     * since (user_id, opportunity_id) is unique at the database level -
     * re-applying is a resubmission, not a second application.
     */
    /**
     * @param  array<string, UploadedFile>  $profileDocuments  profile documents sent with this request, by type. They are
     *                                                      written to the profile only once the guardian, profile,
     *                                                      eligibility, listing and existing-application checks
     *                                                      that do not depend on them have passed.
     */
    public function submit(int $opportunityId, User $user, array $data, ?UploadedFile $document = null, array $profileDocuments = []): Application
    {
        $this->assertSubmissionInput($data, $document, $profileDocuments);

        $opportunity = Opportunity::find($opportunityId);

        // An unapproved post is treated as non-existent rather than forbidden, so
        // the moderation queue is not observable from the outside. This first
        // pass exists only to fail fast with a useful message and to decide
        // whether uploading a document is worth doing at all - it is not
        // trusted as the final answer. The locked, re-read copy inside the
        // transaction below is.
        if (! $opportunity || ! OpportunityModerationStatus::isApproved($opportunity->moderation_status)) {
            throw new RuntimeException('Opportunity not found.');
        }

        $this->assertAcceptingApplications($opportunity);

        $profile = $this->profileService->forUser($user);

        // Gate one, before gate two ever runs: ScholarFit cannot tell whether
        // a listing's requirements are met from a profile that never states
        // the education level, field, institution or academic record it
        // needs to check them against. This is deliberately the same
        // definition ApplicantProfile::isComplete() already uses for the
        // profile checklist and completion ring - not a second, narrower
        // notion of "complete enough to apply" invented here.
        // A minor needs a parent or guardian behind the application. Asked for here, at submission - not
        // before browsing or building a profile - and before the profile gate so it is the first thing said.
        if ($profile->missingGuardianDetails() !== []) {
            throw new \App\Exceptions\GuardianRequiredException($profile->missingGuardianDetails());
        }

        // Documents that came with this request are stored now, not before: nothing is written to the profile for an
        // application that was going to be refused. Every check that does not need them runs first, in the same order
        // and with the same answers as below.
        if ($profileDocuments !== []) {
            $profile = $this->storeSuppliedDocuments($profile, $user, $opportunity, $profileDocuments);
        }

        // Only what is NOT a document is held against the profile here. A required document that is absent, or whose
        // file has gone from storage, is answered below with the document's own name and an upload to put it right
        // (DocumentsRequired) - and a document whose store could not be asked is answered as a storage fault, not as
        // an incomplete profile.
        $profileGaps = $profile->missingFields();

        if (array_diff($profileGaps, array_values(ApplicantProfile::DOCUMENT_LABELS)) !== []) {
            throw new ProfileIncompleteException($profileGaps);
        }

        // The same hard requirements ScholarFit already showed the applicant on
        // the listing page and the wizard, checked again here so the gate is
        // real rather than advisory - a direct POST cannot buy its way past a
        // pathway rule, a minimum level, or any other stated requirement just
        // because the frontend happened not to enforce it.
        //
        // A failure and a requirement that could not be checked are different things: the first is the applicant's answer
        // and nothing they fill in repairs it; the second is "add this to your profile", and is reported as an incomplete
        // profile so the applicant is sent to fix it rather than told they do not qualify.
        $record = AcademicRecord::fromProfile($profile);
        $failures = $this->eligibilityEvaluator->failureMessages($profile, $opportunity, $record);

        if ($failures !== []) {
            throw new RuntimeException(
                "You do not meet this scholarship's requirements: " . implode(' ', $failures)
            );
        }

        $pending = $this->eligibilityEvaluator->pendingMessages($profile, $opportunity, $record);

        if ($pending !== []) {
            throw ProfileIncompleteException::forPendingRequirements($pending);
        }

        // Every document this applicant and this scholarship require, from the same checklist the profile and the
        // apply page show. Enforced here - not only in the wizard - so a one-click Apply, which has no form to carry
        // an upload, cannot go ahead without a passport or a recommendation letter the page calls required. Asked for
        // after eligibility: nobody is told to upload a certificate for an award they cannot be considered for.
        //
        // "Available" means the file can be opened from the disk it is recorded on - a path in the database is not
        // enough. A document the store could not be asked about fails the submission safely rather than being
        // counted as there, or declared missing.
        $checklist = $profile->documentChecklist($opportunity);
        $missingDocuments = $checklist->missing();

        if ($missingDocuments !== []) {
            throw new \App\Exceptions\DocumentsRequired(
                array_column($missingDocuments, 'label'),
                array_column(array_filter($missingDocuments, static fn (array $item) => $item['state'] === \App\Support\DocumentChecklist::FILE_MISSING), 'label')
            );
        }

        if ($checklist->storageUnavailable()) {
            throw new \App\Exceptions\DocumentStorageUnavailable();
        }

        // Anything still outstanding is something the checks above did not name; never let it through.
        if (! $profile->isComplete()) {
            throw new ProfileIncompleteException($profile->missingFields());
        }

        // The upload happens before the transaction because writing a file is not
        // something a database can roll back. Every failure path below therefore
        // deletes it again, so a rejected submission cannot leave a file behind
        // that no row refers to.
        $uploadedPath = null;

        if ($document !== null) {
            $uploadedPath = $this->fileStorage->store($document, 'applications', $user);
        }

        try {
            [$application, $supersededDocument, $releasedDocuments] = DB::transaction(
                function () use ($opportunityId, $user, $data, $document, $uploadedPath, $opportunity) {
                    // The authoritative check. Locked first, opportunity before
                    // application - the same order every writer of either row
                    // uses (see decide()) - so two transactions contending for
                    // both rows always queue behind each other rather than
                    // deadlocking. Nothing read before this line is trusted:
                    // between it and the caller's last look, another request may
                    // have closed, withdrawn, or filled this listing, or pushed
                    // its deadline into the past.
                    $lockedOpportunity = Opportunity::whereKey($opportunityId)->lockForUpdate()->first();

                    if (! $lockedOpportunity || ! OpportunityModerationStatus::isApproved($lockedOpportunity->moderation_status)) {
                        throw new RuntimeException('Opportunity not found.');
                    }

                    $this->assertAcceptingApplications($lockedOpportunity);

                    // Locked, then re-read: between the caller's last look and this
                    // line another request may have created or reopened the very
                    // same application. On MySQL this blocks the second writer
                    // until the first commits, so the checks below run against
                    // committed truth rather than a stale read.
                    $existing = Application::where('user_id', $user->user_id)
                        ->where('opportunity_id', $opportunityId)
                        ->lockForUpdate()
                        ->first();

                    // Re-applying is a resubmission of the same row, so only a
                    // withdrawn attempt may be reopened - one student plus one
                    // scholarship is one application. Reported in the student's
                    // terms rather than as a refused state transition, because
                    // "you cannot move pending to pending" is true and useless.
                    if ($existing && ! ApplicationStateMachine::allowsReapplication($existing->application_status)) {
                        throw new RuntimeException($this->reapplicationRefusal($existing));
                    }

                    $documentPath = $uploadedPath ?? $existing?->document_path;
                    $documentName = $document?->getClientOriginalName() ?? $existing?->document_filename;

                    // A replaced document is only deleted once the new row is
                    // safely committed, so a rollback still leaves the old file
                    // exactly where the surviving row expects it.
                    $superseded = $uploadedPath !== null && $existing?->document_path !== null
                        && $existing->document_path !== $uploadedPath
                            ? $existing->document_path
                            : null;

                    $attributes = [
                        'application_status' => ApplicationStatus::PENDING,
                        'submitted_at' => Carbon::now(),
                        'personal_statement' => $data['personal_statement'] ?? null,
                        'document_path' => $documentPath,
                        'document_filename' => $documentName,
                        // A resubmission is a clean slate: nothing from the
                        // withdrawn attempt may leak into the new one.
                        'decision_reason' => null,
                        'decided_at' => null,
                        'withdrawn_at' => null,
                        'withdrawal_reason' => null,
                        'reopened_at' => null,
                        'reopen_reason' => null,
                        'reopened_from' => null,
                    ];

                    if ($existing) {
                        $existing->update($attributes);
                        $application = $existing;
                    } else {
                        $application = Application::create($attributes + [
                            'user_id' => $user->user_id,
                            'opportunity_id' => $opportunityId,
                        ]);
                    }

                    // What this application goes in with, as it is now. A provider is shown THIS, not whatever the
                    // applicant's profile holds later. Files an earlier submission recorded and this one does not
                    // use any more are released after the commit.
                    $before = \App\Models\ApplicationDocument::where('application_id', $application->application_id)->pluck('path')->all();
                    \App\Models\ApplicationDocument::record($application, $this->profileService->forUser($user));
                    $released = array_values(array_diff($before, \App\Models\ApplicationDocument::where('application_id', $application->application_id)->pluck('path')->all()));
                    $application->update(['submitted_snapshot' => ApplicationSnapshot::build(
                        $snapshotProfile = $this->profileService->forUser($user),
                        new \App\Services\ScholarFit\EligibilityResult(
                            $lockedOpportunity,
                            $this->eligibilityEvaluator->evaluate($snapshotProfile, $lockedOpportunity, AcademicRecord::fromProfile($snapshotProfile)),
                            $snapshotProfile->isComplete(),
                        )
                    )]);

                    // Inside the transaction on purpose: an audit line that
                    // survived a rolled-back submission would be a record of
                    // something that never happened.
                    $this->auditService->logOrFail(
                        $user->email,
                        AuditAction::APPLY,
                        'APPLICATION',
                        $application->application_id,
                        ($existing ? 'Re-applied' : 'Applied') . ' to "' . $opportunity->title . '"'
                    );

                    return [$application, $superseded, $released];
                }
            );
        } catch (UniqueConstraintViolationException $e) {
            // Two submissions raced to insert the first application for this
            // pair and the database settled it. The loser is told what is
            // actually true rather than shown a SQL error.
            $this->fileStorage->delete($uploadedPath);

            throw new RuntimeException('You have already applied to this opportunity.');
        } catch (\Throwable $e) {
            $this->fileStorage->delete($uploadedPath);

            throw $e;
        }

        // Past this line the transaction has committed, so the file the row no
        // longer points at is safe to remove and the notifications below can
        // never announce a submission that was rolled back.
        $this->fileStorage->delete($supersededDocument);

        // A file only an earlier version of this application referred to is let go - unless the applicant's profile
        // still holds it or another application refers to it.
        $profileNow = $this->profileService->forUser($user);
        $held = collect(\App\Models\ApplicationDocument::TYPES)->map(fn ($columns) => $profileNow->{$columns[0]})->filter()->all();

        foreach ($releasedDocuments as $path) {
            if (! in_array($path, $held, true) && ! \App\Models\ApplicationDocument::isReferenced($path)) {
                $this->fileStorage->delete($path);
            }
        }

        $this->notificationService->notifyUser(
            $user,
            NotificationType::APPLICATION_SUBMITTED,
            'Your application to "' . $opportunity->title . '" was submitted.',
            '/applications/' . $application->application_id . '/confirmation',
            $application->application_id
        );

        if ($opportunity->provider) {
            $this->notificationService->notifyUser(
                $opportunity->provider,
                NotificationType::NEW_APPLICATION,
                $user->displayName() . ' applied to "' . $opportunity->title . '".',
                '/provider/applications/' . $application->application_id,
                $application->application_id
            );
        }

        return $application;
    }

    /**
     * The documents of a pending application that cannot be opened, so the applicant can put them right.
     *
     * Every file here is one the provider would find unavailable: recorded against the application but gone from its
     * disk ('file_missing'), asked of this applicant at their submitted level but never recorded ('not_provided'), or
     * a file whose store could not be asked just now ('storage_error' - listed so it is not hidden, but never offered
     * for replacement: nothing is known to be wrong with it). A decided or withdrawn application has no issues: a
     * decision is final and what the provider reviewed is not rewritten afterwards.
     *
     * @return array<int, array{type: string, label: string, state: string}>
     */
    public function documentIssues(Application $application): array
    {
        if (! $application->isPending()) {
            return [];
        }

        $level = $application->submittedLevel();
        $asked = $level !== null ? array_keys(ApplicantProfile::documentRequirementsAt($level, $application->opportunity)) : [];
        $issues = [];

        foreach (array_keys(\App\Models\ApplicationDocument::TYPES) as $type) {
            $document = $application->documentFor($type);

            if ($document === null) {
                if (in_array($type, $asked, true)) {
                    $issues[] = ['type' => $type, 'label' => ApplicantProfile::DOCUMENT_LABELS[$type], 'state' => 'not_provided'];
                }

                continue;
            }

            $state = $this->issueState($document['path']);

            if ($state !== null) {
                $issues[] = ['type' => $type, 'label' => ApplicantProfile::DOCUMENT_LABELS[$type], 'state' => $state];
            }
        }

        // The file attached to the application itself is only a problem if one was attached and has gone.
        if (filled($application->document_path) && ($state = $this->issueState($application->document_path)) !== null) {
            $issues[] = ['type' => self::ATTACHMENT, 'label' => $application->document_filename ?: 'Application attachment', 'state' => $state];
        }

        return $issues;
    }

    private function issueState(string $path): ?string
    {
        return match ($this->fileStorage->status($path)) {
            FileStorageService::AVAILABLE => null,
            FileStorageService::MISSING => 'file_missing',
            default => 'storage_error',
        };
    }

    /** The pseudo-type for the file attached to an application, as opposed to a profile document. */
    public const ATTACHMENT = 'attachment';

    /**
     * Replace a document of a PENDING application whose file cannot be found, so the provider can open it.
     *
     * Uploading to the profile alone does not repair an application: the provider is shown the file the application
     * recorded when it was sent (ApplicationDocument), never the profile's current one. So the replacement is stored
     * through the profile's one writer and then the application's own record is pointed at it - and only for a
     * document that is actually broken, so this cannot be used to swap a good file after the provider has seen it.
     * Refused once the application is decided or withdrawn, and while the store cannot be asked.
     */
    public function repairDocument(int $applicationId, User $user, string $type, UploadedFile $file): Application
    {
        $application = $this->findForApplicant($applicationId, $user);

        if (! $application->isPending()) {
            throw new RuntimeException('Documents can only be replaced while the application is pending. A decided or withdrawn application is final.');
        }

        $issue = collect($this->documentIssues($application))->firstWhere('type', $type);

        if ($issue === null) {
            throw new RuntimeException('That document does not need replacing: the provider can already open it.');
        }

        if ($issue['state'] === 'storage_error') {
            throw new \App\Exceptions\DocumentStorageUnavailable();
        }

        $isAttachment = $type === self::ATTACHMENT;
        $previous = $isAttachment ? $application->document_path : ($application->documentFor($type)['path'] ?? null);

        if ($isAttachment) {
            $newPath = $this->fileStorage->store($file, 'applications', $user);
            $filename = $file->getClientOriginalName();
            $uploadedAt = Carbon::now();
        } else {
            $profile = $this->profileService->storeDocument($user, $type, $file);
            $newPath = $profile->documentPath($type);
            $filename = $profile->documentFilename($type);
            $uploadedAt = $profile->documentUploadedAt($type);
        }

        try {
            DB::transaction(function () use ($application, $type, $isAttachment, $newPath, $filename, $uploadedAt, $user) {
                // Locked and re-read: a provider may have decided in the moment since the checks above.
                $locked = Application::whereKey($application->application_id)->lockForUpdate()->firstOrFail();

                if (! $locked->isPending()) {
                    throw new RuntimeException('Documents can only be replaced while the application is pending. A decided or withdrawn application is final.');
                }

                if ($isAttachment) {
                    $locked->update(['document_path' => $newPath, 'document_filename' => $filename]);
                } else {
                    \App\Models\ApplicationDocument::updateOrCreate(
                        ['application_id' => $locked->application_id, 'type' => $type],
                        ['path' => $newPath, 'filename' => $filename, 'uploaded_at' => $uploadedAt, 'recorded_at' => Carbon::now()]
                    );
                }

                $this->auditService->logOrFail(
                    $user->email,
                    AuditAction::REPLACE_APPLICATION_DOCUMENT,
                    'APPLICATION',
                    $locked->application_id,
                    'Replaced ' . ($isAttachment ? 'the application attachment' : ApplicantProfile::DOCUMENT_LABELS[$type]) . ' on an application whose file could not be found'
                );
            });
        } catch (\Throwable $e) {
            // The attachment is only ever this application's, so a failed rebind leaves it an orphan. A profile
            // document stays: the applicant did upload it to their profile, and the profile row still refers to it.
            if ($isAttachment) {
                $this->fileStorage->delete($newPath);
            }

            throw $e;
        }

        // Committed: the file this application used to point at is released unless something else still does.
        if (filled($previous) && $previous !== $newPath) {
            $held = $isAttachment ? null : $this->profileService->forUser($user)->documentPath($type);

            if ($previous !== $held && ! \App\Models\ApplicationDocument::isReferenced($previous)
                && ! Application::where('document_path', $previous)->exists()) {
                $this->fileStorage->delete($previous);
            }
        }

        return $application->refresh();
    }

    /**
     * Why a second application to the same listing was refused.
     *
     * One message per reason rather than one for all of them: "you have already
     * applied" is true of an accepted application but tells a student nothing
     * they can act on, and it reads as an error when it is in fact the best
     * possible outcome - they already have the scholarship.
     */
    /**
     * Write the documents sent with a submission to the applicant's profile - after the checks they must not
     * outrun, never before.
     *
     * A document is what makes some profiles complete and some scholarships answerable, so the completeness and
     * eligibility checks cannot simply wait for it: here they are applied to everything else (a missing document is
     * not held against the applicant yet) and to every definite refusal. The full gates run again afterwards.
     * Only the types this applicant is actually missing are stored; anything else sent is ignored.
     *
     * @param  array<string, UploadedFile>  $files
     */
    private function storeSuppliedDocuments(ApplicantProfile $profile, User $user, Opportunity $opportunity, array $files): ApplicantProfile
    {
        $otherwiseMissing = array_values(array_diff($profile->missingFields(), array_values(ApplicantProfile::DOCUMENT_LABELS)));

        if ($otherwiseMissing !== []) {
            throw new ProfileIncompleteException($otherwiseMissing);
        }

        $refused = $this->eligibilityEvaluator->failureMessages($profile, $opportunity, AcademicRecord::fromProfile($profile));

        if ($refused !== []) {
            throw new RuntimeException("You do not meet this scholarship's requirements: " . implode(' ', $refused));
        }

        $this->assertAcceptingApplications($opportunity);

        $existing = Application::where('user_id', $user->user_id)->where('opportunity_id', $opportunity->opportunity_id)->first();

        if ($existing && ! ApplicationStateMachine::allowsReapplication($existing->application_status)) {
            throw new RuntimeException($this->reapplicationRefusal($existing));
        }

        foreach ($profile->documentChecklist($opportunity)->missingTypes() as $type) {
            if (isset($files[$type])) {
                $this->profileService->storeDocument($user, $type, $files[$type]);
            }
        }

        return $this->profileService->forUser($user);
    }

    /**
     * The shape of what a caller hands to submit(), checked again here.
     *
     * The controller validates the request, but quickApply(), a command or any future caller reaches this service directly,
     * and a service that trusted its arguments would store whatever it was given. Only the shape is judged here: the
     * personal statement is text within the form's length cap (or absent, as in a one-click apply; its minimum is the wizard's
     * rule, in the controller), the application document and
     * each profile document are real uploads of an accepted type and size, and a profile document is one of the types the
     * profile actually has a slot for. Eligibility, documents required and guardian checks are unchanged and run after.
     *
     * The 100-character minimum is a wizard-policy rule enforced by the controller; the service enforces the shape
     * (nullable string, at most 5,000 characters) because quickApply() deliberately submits a null statement.
     *
     * @param  array<string, mixed>  $profileDocuments
     *
     * @throws ValidationException
     */
    private function assertSubmissionInput(array $data, ?UploadedFile $document, array $profileDocuments): void
    {
        $file = ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'];

        $rules = [
            'personal_statement' => ['bail', 'nullable', 'string', 'max:5000'],
            'document' => ['nullable', ...$file],
        ];
        $input = ['personal_statement' => $data['personal_statement'] ?? null, 'document' => $document];

        foreach ($profileDocuments as $type => $upload) {
            if (! is_string($type) || ! array_key_exists($type, ApplicantProfile::DOCUMENT_TYPES)) {
                throw ValidationException::withMessages(['documents' => 'That is not a document this profile holds.']);
            }

            $rules["documents.$type"] = ['bail', 'required', ...$file];
            $input['documents'][$type] = $upload;
        }

        \Illuminate\Support\Facades\Validator::make($input, $rules)->validate();
    }

    private function reapplicationRefusal(Application $existing): string
    {
        if ($existing->isAccepted()) {
            return 'You have already been accepted for this scholarship and cannot apply again.';
        }

        return 'You have already applied to this opportunity.';
    }

    /**
     * Publication status, deadline, and award capacity - the three reasons a
     * listing that still exists and cleared moderation might nonetheless
     * refuse a new application. Capacity is checked first so a scholarship
     * that is CLOSED specifically because its awards filled up says so,
     * rather than the generic "no longer accepting applications" every other
     * closure reason shares.
     *
     * @throws RuntimeException
     */
    private function assertAcceptingApplications(Opportunity $opportunity): void
    {
        $this->assertCapacityAvailable($opportunity);

        if (strcasecmp((string) $opportunity->status, OpportunityStatus::ACTIVE) !== 0) {
            throw new RuntimeException('This scholarship is no longer accepting applications.');
        }

        if ($opportunity->deadline !== null && $opportunity->deadline->lt(\App\Support\Deadlines::today())) {
            throw new RuntimeException('The deadline for this scholarship has passed.');
        }
    }

    /**
     * award_slots is a cap on ACCEPTED applications, not on applications
     * generally - a listing with 2 slots and 10 PENDING applicants is still
     * open, because none of them has actually been granted the scholarship
     * yet. A null award_slots means no capacity restriction at all.
     */
    private function assertCapacityAvailable(Opportunity $opportunity): void
    {
        if ($opportunity->award_slots === null) {
            return;
        }

        $accepted = Application::where('opportunity_id', $opportunity->opportunity_id)
            ->where('application_status', ApplicationStatus::ACCEPTED)
            ->count();

        if ($accepted >= $opportunity->award_slots) {
            throw new RuntimeException(
                'This scholarship is no longer accepting applications because its available awards have been filled.'
            );
        }
    }

    /** The longest message a provider may leave with an acceptance. */
    public const MAX_ACCEPTANCE_MESSAGE = 1000;

    /** The reason a listing is given when its last award slot is filled and it closes by itself. */
    private const AUTO_CLOSED_REASON = 'Closed automatically: all award slots filled.';

    /**
     * The provider's decision, and the only way a PENDING application is decided.
     *
     * Accepting means the provider has granted the scholarship to this
     * applicant. Rejecting means they have not. Both need a written reason
     * because the applicant is shown it verbatim. A decision is final: a decided
     * application is refused here, and nothing reverses it.
     */
    public function decide(int $applicationId, string $status, ?string $reason, User $provider, ?string $message = null): Application
    {
        // Destination check first - it turns away anything that is not one of the
        // two decisions (a withdrawal, a legacy stage, a value invented by hand)
        // before any work is done. Whether this particular application may be
        // decided is the state machine's call once the row is loaded, below.
        if (! in_array($status, ApplicationStatus::DECISIONS, true)) {
            throw ValidationException::withMessages([
                'status' => 'An application can only be accepted or rejected.',
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when accepting or rejecting an application.',
            ]);
        }

        $reason = trim($reason);

        // The message is part of an acceptance and nothing else: a rejection carries only its reason. Kept as typed
        // (trimmed), never shortened silently - an over-long one is refused so the provider can say it in fewer words.
        $message = $status === ApplicationStatus::ACCEPTED ? trim((string) $message) : '';

        if (mb_strlen($message) > self::MAX_ACCEPTANCE_MESSAGE) {
            throw ValidationException::withMessages([
                'message' => 'Keep the message to ' . self::MAX_ACCEPTANCE_MESSAGE . ' characters or fewer.',
            ]);
        }

        $message = $message === '' ? null : $message;

        // Ownership is settled outside the transaction: it cannot change under
        // us, and a stranger should be turned away without taking a row lock.
        // The opportunity id comes from this same read - a foreign key that
        // cannot itself race - so the transaction below can lock the
        // opportunity before it has any other reason to touch it.
        $opportunityId = $this->findForProvider($applicationId, $provider)->opportunity_id;

        $justClosed = false;

        $application = DB::transaction(function () use ($applicationId, $opportunityId, $status, $reason, $message, $provider, &$justClosed) {
            // Opportunity locked before application - the same order submit()
            // uses - so a submission and a decision contending for the same
            // listing always queue rather than deadlock.
            $opportunity = Opportunity::whereKey($opportunityId)->lockForUpdate()->first();

            // Re-read under a lock rather than trusting the copy fetched above.
            // Two reviewers acting on the same application at the same moment
            // would otherwise both see it pending, and the second write would
            // silently overturn the first - accepting an applicant who had just
            // been rejected, with both notifications already sent. Here the
            // second reviewer waits, sees the decision that landed, and is
            // refused by the state machine.
            $application = Application::whereKey($applicationId)->lockForUpdate()->firstOrFail();

            // Captured under the lock, before the write, so the audit entry
            // records the status this decision actually replaced.
            $previousStatus = $application->application_status;

            ApplicationStateMachine::assertCanTransition(
                $previousStatus,
                $status,
                ApplicationStateMachine::ACTOR_PROVIDER
            );

            // Capacity is checked only on the way to ACCEPTED - rejecting
            // never consumes a slot, so it can never be refused by this.
            if ($status === ApplicationStatus::ACCEPTED && $opportunity !== null && $opportunity->award_slots !== null) {
                $acceptedCount = Application::where('opportunity_id', $opportunity->opportunity_id)
                    ->where('application_status', ApplicationStatus::ACCEPTED)
                    ->count();

                if ($acceptedCount >= $opportunity->award_slots) {
                    throw AwardsFilled::forListing();
                }

                // True exactly when this acceptance is the one that uses the
                // last slot - computed under the same lock that is about to
                // write it, so two providers racing for the last seat cannot
                // both compute "true".
                $justClosed = ($acceptedCount + 1) >= $opportunity->award_slots;
            }

            $application->update([
                'application_status' => $status,
                'decision_reason' => $reason,
                'acceptance_message' => $message,
                'decided_at' => Carbon::now(),
                // Whatever a reopening left on the row has done its job: this is the new decision.
                'reopened_at' => null,
                'reopen_reason' => null,
                'reopened_from' => null,
            ]);

            $this->auditService->logOrFail(
                $provider->email,
                AuditAction::STATUS_UPDATE,
                'APPLICATION',
                $application->application_id,
                ApplicationStatus::displayLabel($status) . ' "'
                    . ($application->opportunity?->title ?? 'a scholarship') . '" application from '
                    . ($application->user?->displayName() ?? 'a deleted user') . ': ' . $reason,
                [
                    'old' => ['application_status' => $previousStatus],
                    'new' => [
                        'application_status' => $status,
                        'decided_at' => $application->decided_at?->toIso8601String(),
                        // Kept with the decision it came with (null when none was left).
                        'acceptance_message' => $message,
                        // The student and the listing the decision belongs to, so
                        // the trail identifies it without a join back to rows
                        // that may since have been deleted.
                        'applicant_user_id' => $application->user_id,
                        'applicant_email' => $application->user?->email,
                        'opportunity_id' => $application->opportunity_id,
                        'opportunity_title' => $application->opportunity?->title,
                    ],
                    'reason' => $reason,
                ]
            );

            // Only ever closes a listing that is still ACTIVE: one already
            // CLOSED or WITHDRAWN for some unrelated reason is left exactly as
            // it was, since OpportunityLifecycle has no ACTIVE-only self
            // transition and none is needed - it is already not accepting
            // applications either way.
            if ($justClosed && $opportunity !== null && OpportunityStatus::isActive($opportunity->status)) {
                $opportunity->update([
                    'status' => OpportunityStatus::CLOSED,
                    'last_change_reason' => self::AUTO_CLOSED_REASON,
                    'updated_at' => Carbon::now(),
                ]);

                $this->auditService->logOrFail(
                    $provider->email,
                    AuditAction::UPDATE_OPPORTUNITY,
                    'OPPORTUNITY',
                    $opportunity->opportunity_id,
                    'Closed "' . $opportunity->title . '" automatically: all award slots filled.'
                );
            } else {
                $justClosed = false;
            }

            return $application;
        });

        // Only now, with the decision committed, is the applicant told about
        // it, and - if this was the acceptance that filled the last slot -
        // the provider told their listing closed. A notification sent before
        // this line could announce a decision that then rolled back.
        $this->notifyApplicantOfDecision($application, $status, $reason, $message !== null);

        if ($justClosed && $application->opportunity?->provider) {
            $this->notificationService->notifyUser(
                $application->opportunity->provider,
                NotificationType::SCHOLARSHIP_CLOSED,
                'Your scholarship "' . $application->opportunity->title . '" was closed automatically because all its award slots have been filled.',
                '/provider/dashboard',
                $application->opportunity->opportunity_id
            );
        }

        return $application;
    }

    /**
     * Tell the applicant. Runs after the decision (and its message) has committed, so a failure to notify can never lose
     * either: NotificationService writes the in-app row, then the email, and records any failure in the audit log
     * instead of throwing.
     *
     * The message itself is never put in the notification or the email - both only say there is one and link to the
     * application page, where it is shown. So nothing the provider wrote travels by email.
     */
    private function notifyApplicantOfDecision(Application $application, string $status, string $reason, bool $hasMessage = false): void
    {
        $applicant = $application->user;

        if (! $applicant) {
            return;
        }

        $title = $application->opportunity?->title ?? 'a scholarship';

        [$type, $message] = $status === ApplicationStatus::ACCEPTED
            ? [
                NotificationType::APPLICATION_ACCEPTED,
                'Congratulations! Your application to "' . $title . '" has been accepted. ' . $reason
                    . ($hasMessage ? ' The provider has also left you a message: open ScholarZim to read it.' : ''),
            ]
            : [
                NotificationType::APPLICATION_REJECTED,
                'Your application to "' . $title . '" was not successful. ' . $reason,
            ];

        $this->notificationService->notifyUser(
            $applicant,
            $type,
            trim($message),
            '/applications/' . $application->application_id . '/confirmation',
            $application->application_id
        );
    }

    /**
     * The applicant pulls out.
     *
     * Nothing is deleted: the provider needs to see that the seat was released
     * rather than have the row vanish from under a decision they were part-way
     * through, and the applicant keeps the history. A withdrawn application does
     * not block a fresh one if they change their mind again.
     */
    public function withdraw(int $applicationId, User $user, ?string $reason = null): Application
    {
        $this->findForApplicant($applicationId, $user);

        $application = DB::transaction(function () use ($applicationId, $user, $reason) {
            // Same reason as a provider decision: the applicant may be pulling
            // out at the exact moment the provider is deciding. Whoever takes
            // the lock first wins, and the loser is refused by the state machine
            // instead of overwriting a status it never saw.
            $application = Application::whereKey($applicationId)->lockForUpdate()->firstOrFail();

            ApplicationStateMachine::assertCanTransition(
                $application->application_status,
                ApplicationStatus::WITHDRAWN,
                ApplicationStateMachine::ACTOR_APPLICANT
            );

            $application->update([
                'application_status' => ApplicationStatus::WITHDRAWN,
                'withdrawn_at' => Carbon::now(),
                'withdrawal_reason' => filled($reason) ? trim($reason) : null,
            ]);

            $this->auditService->logOrFail(
                $user->email,
                AuditAction::WITHDRAW_APPLICATION,
                'APPLICATION',
                $application->application_id,
                'Withdrew application to "' . ($application->opportunity?->title ?? 'a scholarship') . '"'
                    . ($reason ? ': ' . $reason : '')
            );

            return $application;
        });

        $title = $application->opportunity?->title ?? 'a scholarship';
        $provider = $application->opportunity?->provider;

        if ($provider) {
            $this->notificationService->notifyUser(
                $provider,
                NotificationType::APPLICATION_WITHDRAWN,
                $user->displayName() . ' withdrew their application to "' . $title . '".',
                '/provider/applications/' . $application->application_id,
                $application->application_id
            );
        }

        return $application;
    }

    /** Status counts used by the applicant dashboard and provider inbox tabs. */
    public function statusCountsForApplicant(User $user): array
    {
        return Application::where('user_id', $user->user_id)
            ->selectRaw('application_status, COUNT(*) as total')
            ->groupBy('application_status')
            ->pluck('total', 'application_status')
            ->all();
    }

    public function statusCountsForProvider(User $provider): array
    {
        return Application::whereHas('opportunity', fn ($q) => $q->where('provider_user_id', $provider->user_id))
            ->selectRaw('application_status, COUNT(*) as total')
            ->groupBy('application_status')
            ->pluck('total', 'application_status')
            ->all();
    }
}
