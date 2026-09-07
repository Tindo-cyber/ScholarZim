<?php

namespace App\Services;

use App\Exceptions\MailgunSubmissionException;
use App\Mail\ScholarZimMail;
use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * All outbound mail goes through here so delivery failures are audited in one
 * place and never bubble up into a request that was otherwise successful.
 *
 * The full path, and where each step happens:
 *
 *   EmailService          picks the subject and Blade view, builds the payload
 *     -> ScholarZimMail   queued (ShouldQueue) onto the database queue
 *       -> queue worker   Supervisor, `queue:work --queue=default`
 *         -> ScholarZimMail::send()  renders the Blade view
 *           -> MailgunApiService     POSTs to Mailgun's HTTP API
 *             -> Mailgun             accepts, then delivers (or does not)
 *
 * There is no SMTP anywhere in that chain, and Laravel's own mail transport
 * never delivers anything: ScholarZimMail overrides send(), so both the queued
 * path and sendNow() converge on exactly one Mailgun submission per message.
 * Mail::to()->send() below is used only to *enqueue* - for a ShouldQueue
 * mailable it dispatches SendQueuedMailable and returns without touching a
 * transport.
 *
 * The request that triggered the mail returns without waiting on any of it.
 * With QUEUE_CONNECTION=sync - the test and bare-development default - the job
 * runs inline instead, so a Mailgun failure surfaces here synchronously and the
 * boolean below reflects it. Under the database queue it cannot: see send().
 *
 * Swallowing the failure is right for a notification - an administrator
 * approving a listing should not see an error because one of forty recipients
 * bounced - but it is wrong when the email *is* the deliverable, as it is for a
 * verification link. So the outcome is returned rather than only logged, and
 * callers decide: the notification paths ignore it exactly as before, and the
 * ones where a lost email leaves the user stuck can say so. Without this the
 * only trace of a broken mailer was a log line and an audit row, while every
 * screen kept reporting success.
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
                'type' => $type,
                // Never 'message': Illuminate\Mail\Mailer::send() unconditionally
                // overwrites $data['message'] with the Illuminate\Mail\Message
                // being built, right before the view renders - so a view data key
                // of 'message' is silently replaced by that object, not the text
                // handed in here. The view was reading the wrong thing on every
                // call, throwing a TypeError from htmlspecialchars() (Message
                // given, string expected) that this method's try/catch turned
                // into a silent `false` - no crash, no visible error, no email.
                'notificationMessage' => $message,
                'actionUrl' => $link ? url($link) : null,
            ]
        );
    }

    public function sendPasswordReset(User $user, string $token): bool
    {
        return $this->send($user, 'Reset your ScholarZim password', 'emails.password-reset', [
            'actionUrl' => url('/reset-password/' . $token),
        ]);
    }

    public function sendEmailVerification(User $user, string $token): bool
    {
        $sent = $this->send($user, 'Verify your ScholarZim email address', 'emails.verify-email', [
            'actionUrl' => url('/verify-email/' . $token),
        ]);

        // Only audited as sent when it was. A failed send has already written its
        // own EMAIL_DELIVERY_FAILED row, and logging both left a trail saying the
        // link went out on the one occasion it demonstrably had not.
        if ($sent) {
            $this->auditService->log($user->email, AuditAction::EMAIL_VERIFICATION_SENT, 'USER', $user->user_id);
        }

        return $sent;
    }

    public function sendWelcome(User $user): bool
    {
        return $this->send($user, 'Welcome to ScholarZim', 'emails.welcome', []);
    }

    /**
     * The queued payload carries a plain object holding only the fields the
     * templates read, not the User model. A queued row can outlive the record it
     * was built from, and an email that fails on wake-up because the account was
     * since renamed or deleted is worse than one addressed from a snapshot.
     *
     * @return bool whether the message was accepted without error.
     *
     *              What "true" means depends on the queue driver, and the
     *              difference is worth being precise about rather than glossing:
     *
     *                sync     - the job ran inline, so true means Mailgun
     *                           itself accepted the message and false means it
     *                           refused it, with the reason already logged and
     *                           audited by ScholarZimMail.
     *                database - true means the job was written to the queue.
     *                           Mailgun has not been contacted yet. A rejection
     *                           surfaces later, in the worker, and is recorded
     *                           by ScholarZimMail::failed() once the retries are
     *                           exhausted.
     *
     *              Neither ever means delivered. Mailgun accepting a message is
     *              a promise to try, and it will accept mail addressed to a
     *              mailbox that does not exist and drop it minutes later.
     */
    private function send(User $user, string $subject, string $view, array $data): bool
    {
        if (blank($user->email)) {
            return false;
        }

        $recipient = (object) ['full_name' => (string) $user->full_name];

        try {
            Mail::to($user->email, $user->full_name)
                ->send(new ScholarZimMail($subject, $view, $data + ['user' => $recipient]));
        } catch (\Throwable $e) {
            Log::warning('Email delivery failed', ['to' => $user->email, 'error' => $e->getMessage()]);

            // A Mailgun refusal has already been audited by
            // ScholarZimMail::failed(), which is the only place that sees it
            // under the database queue. On the sync driver the same exception
            // keeps travelling up to here, and auditing it again would put two
            // EMAIL_DELIVERY_FAILED rows against one email - a trail implying
            // two lost messages where there was one.
            if (! $e instanceof MailgunSubmissionException) {
                $this->auditService->log(
                    $user->email,
                    AuditAction::EMAIL_DELIVERY_FAILED,
                    'USER',
                    $user->user_id,
                    $e->getMessage()
                );
            }

            return false;
        }

        return true;
    }

    private function subjectFor(string $type): string
    {
        return match ($type) {
            'APPLICATION_SUBMITTED' => 'Your application was submitted',
            'APPLICATION_ACCEPTED' => 'Your scholarship application was accepted',
            'APPLICATION_REJECTED' => 'Update on your scholarship application',
            'APPLICATION_WITHDRAWN' => 'An applicant withdrew their application',
            // Subjects for notification rows written before the workflow was
            // simplified; nothing new is sent with these types.
            'APPLICATION_APPROVED', 'APPLICATION_AWARDED' => 'Your scholarship application was accepted',
            'APPLICATION_INTERVIEW' => 'You have been invited to an interview',
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
