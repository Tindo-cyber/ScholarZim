<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ApplicationService
{
    /** The only transitions a provider is allowed to drive from the review screen. */
    private const PROVIDER_SETTABLE = [
        ApplicationStatus::APPROVED,
        ApplicationStatus::REJECTED,
        ApplicationStatus::UNDER_REVIEW,
        ApplicationStatus::DOCUMENTS_REQUESTED,
        ApplicationStatus::INFO_REQUESTED,
        ApplicationStatus::WAITLISTED,
        ApplicationStatus::SHORTLISTED,
        ApplicationStatus::INTERVIEW,
    ];

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

    /**
     * Statuses that do NOT lock an applicant out of applying again: a rejection
     * is a closed door they may knock on next intake, and a withdrawal was their
     * own decision to step back.
     */
    private const REAPPLIABLE = [
        ApplicationStatus::REJECTED,
        ApplicationStatus::WITHDRAWN,
    ];

    /** True if the applicant has a live application against this listing. */
    public function hasApplied(User $user, int $opportunityId): bool
    {
        return Application::where('user_id', $user->user_id)
            ->where('opportunity_id', $opportunityId)
            ->whereNotIn('application_status', self::REAPPLIABLE)
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
            ->whereNotIn('application_status', self::REAPPLIABLE)
            ->pluck('opportunity_id')
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

        $existing = Application::where('user_id', $user->user_id)
            ->where('opportunity_id', $opportunityId)
            ->first();

        if ($existing && ! in_array($existing->application_status, self::REAPPLIABLE, true)) {
            throw new RuntimeException('You have already applied to this opportunity.');
        }

        $documentPath = $existing?->document_path;
        $documentName = $existing?->document_filename;

        if ($document !== null) {
            $documentPath = $this->fileStorage->store($document, 'applications');
            $documentName = $document->getClientOriginalName();
        }

        $attributes = [
            'application_status' => ApplicationStatus::SUBMITTED,
            'submitted_at' => Carbon::now(),
            'personal_statement' => $data['personal_statement'] ?? null,
            'document_path' => $documentPath,
            'document_filename' => $documentName,
            'rejection_reason' => null,
            'interview_at' => null,
            // A resubmission is a clean slate: nothing from the withdrawn or
            // rejected attempt may leak into the new one.
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

        $this->auditService->log(
            $user->email,
            AuditAction::APPLY,
            'APPLICATION',
            $application->application_id,
            ($existing ? 'Re-applied' : 'Applied') . ' to "' . $opportunity->title . '"'
        );

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
        if (! in_array($status, self::PROVIDER_SETTABLE, true)) {
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

        $application = $this->findForProvider($applicationId, $provider);

        if ($application->isWithdrawn()) {
            throw new RuntimeException('This application was withdrawn by the applicant.');
        }

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

        $this->auditService->log(
            $provider->email,
            AuditAction::STATUS_UPDATE,
            'APPLICATION',
            $application->application_id,
            'Set status to ' . $status . ($reason ? ': ' . $reason : '')
        );

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
        $application = $this->findForApplicant($applicationId, $user);

        if (! $application->canBeWithdrawn()) {
            throw new RuntimeException(
                'This application has already been ' . strtolower($application->statusLabel())
                    . ' and can no longer be withdrawn.'
            );
        }

        $application->update([
            'application_status' => ApplicationStatus::WITHDRAWN,
            'withdrawn_at' => Carbon::now(),
            'withdrawal_reason' => filled($reason) ? trim($reason) : null,
        ]);

        $title = $application->opportunity?->title ?? 'a scholarship';

        $this->auditService->log(
            $user->email,
            AuditAction::WITHDRAW_APPLICATION,
            'APPLICATION',
            $application->application_id,
            'Withdrew application to "' . $title . '"' . ($reason ? ': ' . $reason : '')
        );

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
        $application = $this->findForApplicant($applicationId, $user);

        if (! $application->awaitsApplicantResponse()) {
            throw new RuntimeException('There is nothing to respond to on this application.');
        }

        $application->update([
            'info_response' => trim($response),
            'info_responded_at' => Carbon::now(),
        ]);

        $title = $application->opportunity?->title ?? 'a scholarship';

        $this->auditService->log(
            $user->email,
            AuditAction::PROVIDE_APPLICATION_INFO,
            'APPLICATION',
            $application->application_id,
            'Responded to the information request on "' . $title . '"'
        );

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
