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

    /** True if the applicant has a live application against this listing - a REJECTED one does not lock them out. */
    public function hasApplied(User $user, int $opportunityId): bool
    {
        return Application::where('user_id', $user->user_id)
            ->where('opportunity_id', $opportunityId)
            ->where('application_status', '!=', ApplicationStatus::REJECTED)
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
            ->where('application_status', '!=', ApplicationStatus::REJECTED)
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

        if ($existing && $existing->application_status !== ApplicationStatus::REJECTED) {
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

        $application = $this->findForProvider($applicationId, $provider);

        $application->update([
            'application_status' => $status,
            'rejection_reason' => in_array($status, [ApplicationStatus::REJECTED, ApplicationStatus::INTERVIEW], true)
                ? $reason
                : null,
            'interview_at' => $isInterview ? Carbon::parse($interviewAt) : $application->interview_at,
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
                'More documents are needed for your application to "' . $title . '".',
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
