<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * All outbound mail goes through here so delivery failures are audited in one
 * place and never bubble up into a request that was otherwise successful.
 */
class EmailService
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function sendNotification(User $user, string $type, string $message, ?string $link = null): bool
    {
        return $this->send(
            $user,
            $this->subjectFor($type),
            'emails.notification',
            [
                'user' => $user,
                'type' => $type,
                // Not 'message': Illuminate\Mail\Mailer overwrites that key with the
                // Message instance, so the view would receive an object, not the text.
                'body' => $message,
                'actionUrl' => $link ? url($link) : null,
            ]
        );
    }

    public function sendPasswordReset(User $user, string $token): bool
    {
        return $this->send($user, 'Reset your ScholarZim password', 'emails.password-reset', [
            'user' => $user,
            'actionUrl' => url('/reset-password/' . $token),
        ]);
    }

    public function sendEmailVerification(User $user, string $token): bool
    {
        $sent = $this->send($user, 'Verify your ScholarZim email address', 'emails.verify-email', [
            'user' => $user,
            'actionUrl' => url('/verify-email/' . $token),
        ]);

        // Only recorded once the transport took it. Auditing the send
        // unconditionally left a VERIFICATION_SENT trail for mail that had in
        // fact just failed, which is the opposite of what the trail is for.
        if ($sent) {
            $this->auditService->log($user->email, AuditAction::EMAIL_VERIFICATION_SENT, 'USER', $user->user_id);
        }

        return $sent;
    }

    public function sendWelcome(User $user): bool
    {
        return $this->send($user, 'Welcome to ScholarZim', 'emails.welcome', ['user' => $user]);
    }

    /**
     * @return bool whether the transport accepted the message. Callers that tell
     *              a user their mail is on its way must check it rather than
     *              assume, or they report a success that never happened.
     */
    private function send(User $user, string $subject, string $view, array $data): bool
    {
        if (blank($user->email)) {
            Log::error('Email skipped: account has no address', [
                'user_id' => $user->user_id,
                'subject' => $subject,
            ]);

            return false;
        }

        try {
            Mail::send($view, $data, static function ($mail) use ($user, $subject) {
                $mail->to($user->email, $user->full_name)->subject($subject);
            });

            return true;
        } catch (\Throwable $e) {
            // Still not re-thrown: a failed notification must not roll back the
            // registration or password reset that triggered it. But it is logged
            // at error with the exception and the active mailer attached, because
            // a warning carrying only getMessage() let a transport that was
            // configured to send nowhere look healthy for as long as nobody
            // happened to read the log.
            Log::error('Email delivery failed', [
                'to' => $user->email,
                'subject' => $subject,
                'mailer' => config('mail.default'),
                'exception' => $e,
            ]);
            $this->auditService->log(
                $user->email,
                AuditAction::EMAIL_DELIVERY_FAILED,
                'USER',
                $user->user_id,
                $e->getMessage()
            );

            return false;
        }
    }

    private function subjectFor(string $type): string
    {
        return match ($type) {
            'APPLICATION_APPROVED' => 'Your scholarship application was approved',
            'APPLICATION_REJECTED' => 'Update on your scholarship application',
            'DOCUMENTS_REQUESTED' => 'Documents requested for your application',
            'DEADLINE_REMINDER' => 'A scholarship deadline is approaching',
            'NEW_OPPORTUNITY' => 'A new scholarship matching your profile',
            'NEW_APPLICATION' => 'You received a new application',
            'PROVIDER_APPROVED' => 'Your provider account was approved',
            'PROVIDER_REJECTED' => 'Update on your provider account',
            'SCHOLARSHIP_APPROVED' => 'Your scholarship post is now live',
            'SCHOLARSHIP_REJECTED' => 'Your scholarship post needs changes',
            'SCHOLARSHIP_PENDING_REVIEW' => 'A scholarship is awaiting review',
            default => 'ScholarZim notification',
        };
    }
}
