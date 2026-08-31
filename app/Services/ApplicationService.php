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

    /** True if the applicant already has an application against this listing. */
    public function hasApplied(User $user, int $opportunityId): bool
    {
        return Application::where('user_id', $user->user_id)
            ->where('opportunity_id', $opportunityId)
            ->blockingReapplication()
            ->exists();
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
     * One message per reason rather than one for all of them: "you have already
     * applied" is true of an accepted application but tells a student nothing
     * they can act on, and it reads as an error when it is in fact the best
     * possible outcome - they already have the scholarship.
     */
    private function reapplicationRefusal(Application $existing): string
    {
        if ($existing->isAccepted()) {
            return 'You have already been accepted for this scholarship and cannot apply again.';
        }

        return 'You have already applied to this opportunity.';
    }

    /**
     * The provider's decision, and the only way an application is decided.
     *
     * Accepting means the provider has granted the scholarship to this
     * applicant. Rejecting means they have not. Both are final, and both need a
     * written reason because the applicant is shown it verbatim.
     */
    public function decide(int $applicationId, string $status, ?string $reason, User $provider): Application
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

        // Ownership is settled outside the transaction: it cannot change under
        // us, and a stranger should be turned away without taking a row lock.
        $this->findForProvider($applicationId, $provider);

        $application = DB::transaction(function () use ($applicationId, $status, $reason, $provider) {
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

            $application->update([
                'application_status' => $status,
                'decision_reason' => $reason,
                'decided_at' => Carbon::now(),
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

            return $application;
        });

        // Only now, with the decision committed, is the applicant told about it.
        $this->notifyApplicantOfDecision($application, $status, $reason);

        return $application;
    }

    private function notifyApplicantOfDecision(Application $application, string $status, string $reason): void
    {
        $applicant = $application->user;

        if (! $applicant) {
            return;
        }

        $title = $application->opportunity?->title ?? 'a scholarship';

        [$type, $message] = $status === ApplicationStatus::ACCEPTED
            ? [
                NotificationType::APPLICATION_ACCEPTED,
                'Congratulations! Your application to "' . $title . '" has been accepted. ' . $reason,
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
