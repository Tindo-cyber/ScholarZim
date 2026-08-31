<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
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

class ApplicationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly AuditService $auditService,
        private readonly FileStorageService $fileStorage,
    ) {
    }

    public function forApplicant(User $user)
    {
        return Application::with('opportunity.provider')
            ->where('user_id', $user->user_id)
            ->orderByDesc('submitted_at')
            ->get();
    }

    public function paginateForApplicant(User $user, ?string $status = null, int $perPage = 10)
    {
        $query = Application::with('opportunity.provider')
            ->where('user_id', $user->user_id)
            ->orderByDesc('submitted_at');

        if (filled($status)) {
            $query->where('application_status', $status);
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

    /** True if the applicant has a live application against this listing. */
    public function hasApplied(User $user, int $opportunityId): bool
    {
        return Application::where('user_id', $user->user_id)
            ->where('opportunity_id', $opportunityId)
            ->blockingReapplication()
            ->exists();
    }

    /**
     * Opportunity ids this applicant can no longer apply to (submitted, under
     * review, approved, etc.) - a prior REJECTED application is excluded so
     * those listings still show an "Apply" action instead of "Applied".
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
     * This applicant's awarded applications, keyed by the listing they belong to.
     *
     * A companion to appliedIds() for the listing pages: an award is a subset of
     * "already applied", and the cards need to say which subset. Told apart in
     * the UI because "Applied" on a scholarship a student has actually won reads
     * as though nothing has happened yet.
     *
     * @return array<int, Application>
     */
    public function awardsByOpportunity(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return Application::where('user_id', $user->user_id)
            ->where('application_status', ApplicationStatus::AWARDED)
            ->get(['application_id', 'opportunity_id', 'awarded_at'])
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
     * still be inside its deadline, and the applicant must not already have a
     * live (non-rejected) application against it.
     *
     * A prior REJECTED application reuses its row rather than inserting a new
     * one, since (user_id, opportunity_id) is unique at the database level -
     * re-applying is a resubmission, not a second application.
     */
    public function submit(int $opportunityId, User $user, array $data, ?UploadedFile $document = null): Application
    {
        $opportunity = Opportunity::find($opportunityId);

        // An unapproved post is treated as non-existent rather than forbidden, so
        // the moderation queue is not observable from the outside.
        if (! $opportunity || ! OpportunityModerationStatus::isApproved($opportunity->moderation_status)) {
            throw new RuntimeException('Opportunity not found.');
        }

        if (strcasecmp((string) $opportunity->status, OpportunityStatus::ACTIVE) !== 0) {
            throw new RuntimeException('This scholarship is no longer accepting applications.');
        }

        if ($opportunity->deadline !== null && $opportunity->deadline->lt(Carbon::today())) {
            throw new RuntimeException('The deadline for this scholarship has passed.');
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
            [$application, $supersededDocument] = DB::transaction(
                function () use ($opportunityId, $user, $data, $document, $uploadedPath, $opportunity) {
                    // Locked, then re-read: between the caller's last look and this
                    // line another request may have created or reopened the very
                    // same application. On MySQL this blocks the second writer
                    // until the first commits, so the checks below run against
                    // committed truth rather than a stale read.
                    $existing = Application::where('user_id', $user->user_id)
                        ->where('opportunity_id', $opportunityId)
                        ->lockForUpdate()
                        ->first();

                    // Re-applying is a resubmission of the same row, so it is a
                    // transition like any other: only a rejected or withdrawn
                    // attempt may be reopened, and an approved one never can.
                    if ($existing) {
                        if (! ApplicationStateMachine::allowsReapplication($existing->application_status)) {
                            throw new RuntimeException($this->reapplicationRefusal($existing));
                        }

                        ApplicationStateMachine::assertCanTransition(
                            $existing->application_status,
                            ApplicationStatus::SUBMITTED,
                            ApplicationStateMachine::ACTOR_APPLICANT
                        );
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
                        'application_status' => ApplicationStatus::SUBMITTED,
                        'submitted_at' => Carbon::now(),
                        'personal_statement' => $data['personal_statement'] ?? null,
                        'document_path' => $documentPath,
                        'document_filename' => $documentName,
                        'rejection_reason' => null,
                        'interview_at' => null,
                        // A resubmission is a clean slate: nothing from the
                        // withdrawn or rejected attempt may leak into the new one.
                        'withdrawn_at' => null,
                        'withdrawal_reason' => null,
                        'info_request' => null,
                        'info_requested_at' => null,
                        'info_response' => null,
                        'info_responded_at' => null,
                        'interview_reminded_at' => null,
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

                    return [$application, $superseded];
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
     * Why a second application to the same listing was refused.
     *
     * One message per reason rather than one message for all of them. "You have
     * already applied" is true of an award but tells a student nothing they can
     * act on, and it reads as an error when it is in fact the best possible
     * outcome - they already have the scholarship.
     */
    private function reapplicationRefusal(Application $existing): string
    {
        if ($existing->isAwarded()) {
            return 'You have already been awarded this scholarship and cannot apply again.';
        }

        return 'You have already applied to this opportunity.';
    }

    /**
     * The provider grants the award.
     *
     * Kept out of updateStatus() on purpose. That method is the review path: its
     * destination allow-list is ApplicationStatus::REVIEWABLE, it is what the
     * review dropdown and the bulk action post to, and awarding through it would
     * make an award something a provider could apply to fifty applications with
     * one click. Awarding is a single, deliberate act on one application that
     * has already been approved, so it gets its own entry point with its own
     * authorisation - and REVIEWABLE stays the thing that cannot reach AWARDED.
     */
    public function award(int $applicationId, User $provider): Application
    {
        // Ownership first, outside the transaction: it cannot change under us,
        // and a provider reaching for someone else's applicant is turned away
        // without taking a row lock.
        $this->findForProvider($applicationId, $provider);

        $application = DB::transaction(function () use ($applicationId, $provider) {
            // Two clicks on the same button, or two people in the same provider
            // account, arrive here at once. The lock serialises them; the second
            // then reads AWARDED - a status the machine allows nothing out of -
            // and is refused, so there is exactly one award, one timestamp, one
            // audit line and one notification however many requests arrived.
            $application = Application::whereKey($applicationId)->lockForUpdate()->firstOrFail();

            $previousStatus = $application->application_status;

            ApplicationStateMachine::assertCanTransition(
                $previousStatus,
                ApplicationStatus::AWARDED,
                ApplicationStateMachine::ACTOR_PROVIDER
            );

            $application->update([
                'application_status' => ApplicationStatus::AWARDED,
                // Never overwritten: the transition above is only reachable from
                // APPROVED, so a row that already carries a stamp cannot get
                // here. The guard is belt and braces for a hand-edited row.
                'awarded_at' => $application->awarded_at ?? Carbon::now(),
            ]);

            $this->auditService->logOrFail(
                $provider->email,
                AuditAction::AWARD_APPLICATION,
                'APPLICATION',
                $application->application_id,
                'Awarded "' . ($application->opportunity?->title ?? 'a scholarship') . '" to '
                    . ($application->user?->displayName() ?? 'a deleted user'),
                [
                    'old' => ['application_status' => $previousStatus],
                    'new' => [
                        'application_status' => ApplicationStatus::AWARDED,
                        'awarded_at' => $application->awarded_at?->toIso8601String(),
                        // The student and the listing the award belongs to, so
                        // the trail identifies the award without a join back to
                        // rows that may since have been deleted.
                        'applicant_user_id' => $application->user_id,
                        'applicant_email' => $application->user?->email,
                        'opportunity_id' => $application->opportunity_id,
                        'opportunity_title' => $application->opportunity?->title,
                    ],
                ]
            );

            return $application;
        });

        // Committed: the award exists, so announcing it cannot be a lie.
        $applicant = $application->user;

        if ($applicant) {
            $this->notificationService->notifyUser(
                $applicant,
                NotificationType::APPLICATION_AWARDED,
                'Congratulations! Your application for "'
                    . ($application->opportunity?->title ?? 'a scholarship') . '" has been awarded.',
                '/applications/' . $application->application_id . '/confirmation',
                $application->application_id
            );
        }

        return $application;
    }

    /**
     * Provider decision. Approving or rejecting requires a written reason - it is
     * shown to the applicant verbatim, so it cannot be left blank.
     */
    public function updateStatus(
        int $applicationId,
        string $status,
        ?string $reason,
        User $provider,
        ?string $interviewAt = null
    ): Application {
        // Destination check only - it rejects a status no provider may ever set
        // (SUBMITTED, PENDING, WITHDRAWN, or a value invented by hand) before any
        // work is done. Whether this particular application may go there is the
        // state machine's call once the row is loaded, below.
        if (! in_array($status, ApplicationStatus::REVIEWABLE, true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid application status: ' . $status,
            ]);
        }

        $isDecision = in_array($status, [ApplicationStatus::APPROVED, ApplicationStatus::REJECTED], true);

        if ($isDecision && blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when approving or rejecting an application.',
            ]);
        }

        $isInterview = $status === ApplicationStatus::INTERVIEW;

        if ($isInterview && blank($interviewAt)) {
            throw ValidationException::withMessages([
                'interview_at' => 'An interview date and time is required when scheduling an interview.',
            ]);
        }

        $isQuestion = ApplicationStatus::awaitsApplicant($status);

        if ($isQuestion && blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'Tell the applicant what you need from them.',
            ]);
        }

        // Ownership is settled outside the transaction: it cannot change under
        // us, and a stranger should be turned away without taking a row lock.
        $this->findForProvider($applicationId, $provider);

        $application = DB::transaction(function () use (
            $applicationId,
            $status,
            $reason,
            $provider,
            $interviewAt,
            $isInterview,
            $isQuestion
        ) {
            // Re-read under a lock rather than trusting the copy fetched above.
            // Two reviewers acting on the same application at the same moment
            // would otherwise both see it live, and the second write would
            // silently overturn the first - approving an applicant who had just
            // been rejected, with both notifications already sent. Here the
            // second reviewer waits, sees the decision that landed, and is
            // refused by the state machine.
            $application = Application::whereKey($applicationId)->lockForUpdate()->firstOrFail();

            // Captured under the lock, before the write, so the audit entry
            // records the status this decision actually replaced rather than
            // whatever a re-read afterwards would have found.
            $previousStatus = $application->application_status;

            ApplicationStateMachine::assertCanTransition(
                $application->application_status,
                $status,
                ApplicationStateMachine::ACTOR_PROVIDER
            );

            $application->update([
                'application_status' => $status,
                'rejection_reason' => in_array($status, [ApplicationStatus::REJECTED, ApplicationStatus::INTERVIEW], true)
                    ? $reason
                    : null,
                'interview_at' => $isInterview ? Carbon::parse($interviewAt) : $application->interview_at,
                // Rescheduling must earn a fresh reminder, so the stamp the reminder
                // job sets is cleared whenever the date is (re)written.
                'interview_reminded_at' => $isInterview ? null : $application->interview_reminded_at,
                // Asking again re-opens the reply box, which is why the timestamp is
                // rewritten rather than only set the first time.
                'info_request' => $isQuestion ? $reason : $application->info_request,
                'info_requested_at' => $isQuestion ? Carbon::now() : $application->info_requested_at,
            ]);

            $this->auditService->logOrFail(
                $provider->email,
                AuditAction::STATUS_UPDATE,
                'APPLICATION',
                $application->application_id,
                'Set status to ' . $status . ($reason ? ': ' . $reason : ''),
                [
                    'old' => ['application_status' => $previousStatus],
                    'new' => ['application_status' => $status],
                    'reason' => $reason,
                ]
            );

            return $application;
        });

        // Only now, with the decision committed, is the applicant told about it.
        $this->notifyApplicantOfDecision($application, $status, $reason);

        return $application;
    }

    private function notifyApplicantOfDecision(Application $application, string $status, ?string $reason): void
    {
        $applicant = $application->user;
        if (! $applicant) {
            return;
        }

        $title = $application->opportunity?->title ?? 'a scholarship';
        $link = '/applications/' . $application->application_id . '/confirmation';

        [$type, $message] = match ($status) {
            ApplicationStatus::APPROVED => [
                NotificationType::APPLICATION_APPROVED,
                'Congratulations - your application to "' . $title . '" was approved. ' . $reason,
            ],
            ApplicationStatus::REJECTED => [
                NotificationType::APPLICATION_REJECTED,
                'Your application to "' . $title . '" was not successful. ' . $reason,
            ],
            ApplicationStatus::DOCUMENTS_REQUESTED => [
                NotificationType::DOCUMENTS_REQUESTED,
                'More documents are needed for your application to "' . $title . '": ' . $reason,
            ],
            ApplicationStatus::INFO_REQUESTED => [
                NotificationType::INFO_REQUESTED,
                'The provider has a question about your application to "' . $title . '": ' . $reason,
            ],
            ApplicationStatus::WAITLISTED => [
                NotificationType::APPLICATION_WAITLISTED,
                'Your application to "' . $title . '" has been waitlisted.',
            ],
            ApplicationStatus::INTERVIEW => [
                NotificationType::APPLICATION_INTERVIEW,
                'You have been invited to interview for "' . $title . '" on '
                    . $application->interview_at?->format('d M Y \a\t g:i A') . '. ' . $reason,
            ],
            default => [
                NotificationType::APPLICATION_UNDER_REVIEW,
                'Your application to "' . $title . '" is now ' . ApplicationStatus::displayLabel($status) . '.',
            ],
        };

        $this->notificationService->notifyUser(
            $applicant,
            $type,
            trim($message),
            $link,
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

    /**
     * The applicant answers a provider's question or document request.
     *
     * The status is deliberately left where the provider put it: they asked, so
     * they decide when the answer moves the application on. What changes is that
     * the reply box closes and the provider is told there is something to read.
     */
    public function respondToInfoRequest(int $applicationId, User $user, string $response): Application
    {
        $this->findForApplicant($applicationId, $user);

        $application = DB::transaction(function () use ($applicationId, $user, $response) {
            // Locked so the answer cannot be written against a request the
            // provider withdrew or superseded a moment ago.
            $application = Application::whereKey($applicationId)->lockForUpdate()->firstOrFail();

            if (! $application->awaitsApplicantResponse()) {
                throw new RuntimeException('There is nothing to respond to on this application.');
            }

            $application->update([
                'info_response' => trim($response),
                'info_responded_at' => Carbon::now(),
            ]);

            $this->auditService->logOrFail(
                $user->email,
                AuditAction::PROVIDE_APPLICATION_INFO,
                'APPLICATION',
                $application->application_id,
                'Responded to the information request on "'
                    . ($application->opportunity?->title ?? 'a scholarship') . '"'
            );

            return $application;
        });

        $title = $application->opportunity?->title ?? 'a scholarship';
        $provider = $application->opportunity?->provider;

        if ($provider) {
            $this->notificationService->notifyUser(
                $provider,
                NotificationType::INFO_PROVIDED,
                $user->displayName() . ' answered your question about "' . $title . '".',
                '/provider/applications/' . $application->application_id,
                $application->application_id
            );
        }

        return $application;
    }

    /**
     * One decision applied to a whole selection.
     *
     * Each application still goes through updateStatus(), so ownership, the
     * written-reason rule, notifications, and the audit trail are identical to
     * the one-at-a-time path - the only thing bulk about this is the click.
     * Failures are collected rather than thrown, so one ineligible row cannot
     * silently discard the rest of the batch.
     *
     * @param  array<int, int|string>  $applicationIds
     * @return array{updated: int, failed: array<int, string>}
     */
    public function bulkUpdateStatus(array $applicationIds, string $status, ?string $reason, User $provider): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $applicationIds))));

        if ($ids === []) {
            throw ValidationException::withMessages([
                'applications' => 'Select at least one application first.',
            ]);
        }

        if ($status === ApplicationStatus::INTERVIEW) {
            // An interview needs a date per applicant, which a bulk action has no
            // sensible way to ask for.
            throw ValidationException::withMessages([
                'status' => 'Interviews must be scheduled one applicant at a time.',
            ]);
        }

        $updated = 0;
        $failed = [];

        foreach ($ids as $id) {
            try {
                $this->updateStatus($id, $status, $reason, $provider);
                $updated++;
            } catch (\Throwable $e) {
                $failed[] = '#' . $id . ': ' . $e->getMessage();
            }
        }

        $this->auditService->log(
            $provider->email,
            AuditAction::BULK_STATUS_UPDATE,
            'APPLICATION',
            null,
            'Set ' . $updated . ' application(s) to ' . $status . ($reason ? ': ' . $reason : '')
        );

        return ['updated' => $updated, 'failed' => $failed];
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
