<?php

namespace App\Services;

use App\Mail\ScholarZimMail;
use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * All outbound mail goes through here so delivery failures are audited in one
 * place and never bubble up into a request that was otherwise successful.
 *
 * Mail is handed to ScholarZimMail, which is queued: the request that triggered
 * it returns without waiting on SMTP. With QUEUE_CONNECTION=sync - the test and
 * bare-development default - that is still immediate, so behaviour is unchanged
 * where no worker is running.
 */
class EmailService
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function sendNotification(User $user, string $type, string $message, ?string $link = null): void
    {
        $this->send(
            $user,
            $this->subjectFor($type),
            'emails.notification',
            [
                'type' => $type,
                'message' => $message,
                'actionUrl' => $link ? url($link) : null,
            ]
        );
    }

    public function sendPasswordReset(User $user, string $token): void
    {
        $this->send($user, 'Reset your ScholarZim password', 'emails.password-reset', [
            'actionUrl' => url('/reset-password/' . $token),
        ]);
    }

    public function sendEmailVerification(User $user, string $token): void
    {
        $this->send($user, 'Verify your ScholarZim email address', 'emails.verify-email', [
            'actionUrl' => url('/verify-email/' . $token),
        ]);

        $this->auditService->log($user->email, AuditAction::EMAIL_VERIFICATION_SENT, 'USER', $user->user_id);
    }

    public function sendWelcome(User $user): void
    {
        $this->send($user, 'Welcome to ScholarZim', 'emails.welcome', []);
    }

    /**
     * The queued payload carries a plain object holding only the fields the
     * templates read, not the User model. A queued row can outlive the record it
     * was built from, and an email that fails on wake-up because the account was
     * since renamed or deleted is worse than one addressed from a snapshot.
     */
    private function send(User $user, string $subject, string $view, array $data): void
    {
        if (blank($user->email)) {
            return;
        }

        $recipient = (object) ['full_name' => (string) $user->full_name];

        try {
            Mail::to($user->email, $user->full_name)
                ->send(new ScholarZimMail($subject, $view, $data + ['user' => $recipient]));
        } catch (\Throwable $e) {
            Log::warning('Email delivery failed', ['to' => $user->email, 'error' => $e->getMessage()]);
            $this->auditService->log(
                $user->email,
                AuditAction::EMAIL_DELIVERY_FAILED,
                'USER',
                $user->user_id,
                $e->getMessage()
            );
        }
    }

    private function subjectFor(string $type): string
    {
        return match ($type) {
            'APPLICATION_APPROVED' => 'Your scholarship application was approved',
            'APPLICATION_REJECTED' => 'Update on your scholarship application',
            'APPLICATION_INTERVIEW' => 'You have been invited to an interview',
            'APPLICATION_WITHDRAWN' => 'An applicant withdrew their application',
            'INTERVIEW_REMINDER' => 'Your interview is tomorrow',
            'DOCUMENTS_REQUESTED' => 'Documents requested for your application',
            'INFO_REQUESTED' => 'A provider has a question about your application',
            'INFO_PROVIDED' => 'An applicant answered your question',
            'DEADLINE_REMINDER' => 'A scholarship deadline is approaching',
            'NEW_OPPORTUNITY' => 'A new scholarship matching your profile',
            'NEW_APPLICATION' => 'You received a new application',
            'PROVIDER_APPROVED' => 'Your provider account was approved',
            'PROVIDER_REJECTED' => 'Update on your provider account',
            'SCHOLARSHIP_APPROVED' => 'Your scholarship post is now live',
            'SCHOLARSHIP_REJECTED' => 'Your scholarship post needs changes',
            'SCHOLARSHIP_PENDING_REVIEW' => 'A scholarship is awaiting review',
            'SCHOLARSHIP_CLOSED' => 'Your scholarship post was archived',
            'SCHOLARSHIP_SEARCH_MATCH' => 'A new scholarship matches your saved search',
            default => 'ScholarZim notification',
        };
    }
}
