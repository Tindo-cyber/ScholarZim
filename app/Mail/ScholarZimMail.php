<?php

namespace App\Mail;

use App\Exceptions\MailgunSubmissionException;
use App\Services\AuditService;
use App\Services\MailgunApiService;
use App\Support\AuditAction;
use App\Support\MailgunResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Every outbound ScholarZim email, as one queued mailable.
 *
 * The subject and Blade view are chosen by EmailService; this class exists so
 * mail leaves the request that triggered it. Approving a listing notifies every
 * matching applicant, and doing that inline made the administrator wait on one
 * round trip per recipient.
 *
 * Only scalars are carried, never models: the payload is serialised into the
 * jobs table, and a queued row that outlives the record it points at would fail
 * on wake-up.
 *
 * **This mailable renders through Blade but does not deliver through Laravel's
 * mail transport.** send() is overridden to submit the rendered HTML straight to
 * Mailgun's HTTP API instead. Render blocks outbound SMTP on its free tier, so
 * an SMTP transport there does not fail - it hangs until the worker times out,
 * which is a far worse failure than a refusal. Going over HTTPS also means the
 * status code Mailgun returns survives all the way into the log, rather than
 * being flattened into "Unable to send an email".
 *
 * The queueing itself is untouched: still ShouldQueue, still the database queue,
 * still drained by the Supervisor worker on the `default` queue. What changed is
 * only what happens when the worker executes the job.
 */
class ScholarZimMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Growing waits between attempts, rather than one fixed minute.
     *
     * The failure this retries through is usually a provider being briefly
     * unavailable, and three attempts a minute apart all land inside the same
     * outage. Spreading them over a quarter of an hour gives Mailgun time to
     * come back before the message is written off as failed.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Property names avoid Mailable's own $view / $subject, which are not
     * readonly on the parent and cannot be redeclared as such.
     */
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $viewName,
        private readonly array $payload,
    ) {
        // Held back until the transaction that produced it commits.
        //
        // The services already call the notification layer after their
        // DB::transaction() block returns, so in practice the mail is queued
        // after the commit either way. This makes the guarantee structural
        // rather than a property of call ordering: with the database queue
        // driver the job row would roll back along with everything else, but on
        // redis or SQS it would not, and an email announcing an approval that
        // never happened is not a mistake anyone can take back.
        //
        // Set through the Queueable trait's own method rather than by
        // redeclaring its $afterCommit property, which is untyped and cannot be
        // narrowed without a fatal composition error.
        $this->afterCommit();
    }

    public function build(): self
    {
        return $this->subject($this->subjectLine)->view($this->viewName, $this->payload);
    }

    /**
     * Where the message is actually submitted.
     *
     * Laravel calls this from SendQueuedMailable::handle() when the worker picks
     * the job up, and directly when a caller uses sendNow(). Both converge here,
     * so there is exactly one Mailgun submission per queued email regardless of
     * which path produced it, and no route by which Laravel's own transport gets
     * involved. The $mailer argument is deliberately unused: accepting it keeps
     * the parent's contract intact for anything that still calls send(), while
     * the delivery decision lives here.
     *
     * A failed submission **throws**. That is what makes the retry policy above
     * real - the queue only retries a job that fails - and it is why nothing is
     * quietly swallowed. After the last attempt Laravel calls failed() below.
     *
     * @param  \Illuminate\Contracts\Mail\Factory|\Illuminate\Contracts\Mail\Mailer  $mailer
     */
    public function send($mailer): void
    {
        // Only the Mailgun setup takes the API path. The other two configured
        // transports are real and still wanted: `docker compose up` points
        // MAIL_MAILER at the bundled MailHog so local development never sends
        // anything outside the machine, and the suite uses the array transport.
        // Routing those through the Mailgun API would post local test mail to a
        // live provider - and would have done so from every developer's laptop.
        if (config('mail.default') !== 'mailgun') {
            parent::send($mailer);

            return;
        }

        $recipient = $this->primaryRecipient();

        if ($recipient === null) {
            // Nothing to do, and nothing worth retrying: a mailable with no
            // recipient is a programming error, not a transient failure.
            Log::warning('Email skipped: no recipient', ['subject' => $this->subjectLine]);

            return;
        }

        $result = $this->mailgun()->send(
            $recipient['address'],
            $recipient['name'],
            $this->subjectLine,
            $this->render()
        );

        if ($result->success) {
            Log::info('Email accepted by Mailgun', [
                'to' => $recipient['address'],
                'subject' => $this->subjectLine,
                'status' => $result->status,
                'mailgun_id' => $result->messageId,
            ]);

            return;
        }

        $this->logFailure($recipient['address'], $result);

        throw new MailgunSubmissionException($recipient['address'], $result);
    }

    /**
     * Called once the last attempt has failed.
     *
     * Laravel has already written the job to failed_jobs by this point, which is
     * where it can be retried from; this adds the two things that row does not
     * make obvious - who was supposed to receive it and why it failed - without
     * needing to unserialise the payload to find out.
     *
     * The audit row is written here rather than on every attempt. EmailService
     * records failures it can see at dispatch time; a failure inside the worker
     * is only final once the retries are exhausted, and writing a row per
     * attempt would make three transport blips look like three lost emails.
     */
    public function failed(\Throwable $e): void
    {
        $recipients = array_column($this->to, 'address');

        Log::error('Email permanently failed after all retries', [
            'subject' => $this->subjectLine,
            'view' => $this->viewName,
            'recipients' => $recipients,
            'error' => $e->getMessage(),
        ]);

        foreach ($recipients as $address) {
            app(AuditService::class)->log(
                (string) $address,
                AuditAction::EMAIL_DELIVERY_FAILED,
                'USER',
                null,
                $e->getMessage()
            );
        }
    }

    /**
     * Resolved from the container rather than injected: a mailable is
     * serialised into the jobs table, and a constructor-injected service would
     * have to survive that round trip.
     */
    private function mailgun(): MailgunApiService
    {
        return app(MailgunApiService::class);
    }

    /** @return array{address: string, name: string|null}|null */
    private function primaryRecipient(): ?array
    {
        $first = $this->to[0] ?? null;

        if (! is_array($first) || blank($first['address'] ?? null)) {
            return null;
        }

        return [
            'address' => (string) $first['address'],
            'name' => filled($first['name'] ?? null) ? (string) $first['name'] : null,
        ];
    }

    /** Everything here is safe to log: MailgunResult never carries the credential. */
    private function logFailure(string $address, MailgunResult $result): void
    {
        Log::error('Mailgun rejected an email', [
            'to' => $address,
            'subject' => $this->subjectLine,
            'view' => $this->viewName,
            'status' => $result->status,
            'reason' => $result->reason,
            'error' => $result->error,
        ]);
    }
}
