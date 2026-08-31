<?php

namespace App\Mail;

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
 * SMTP round trip per recipient.
 *
 * Only scalars are carried, never models: the payload is serialised into the
 * jobs table, and a queued row that outlives the record it points at would fail
 * on wake-up.
 */
class ScholarZimMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Growing waits between attempts, rather than one fixed minute.
     *
     * The failure this retries through is usually an SMTP provider being briefly
     * unavailable, and three attempts a minute apart all land inside the same
     * outage. Spreading them over a quarter of an hour gives the provider time
     * to come back before the message is written off as failed.
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
     * Called once the last attempt has failed.
     *
     * Laravel has already written the job to failed_jobs by this point, which is
     * where it can be retried from; this adds the one thing that row does not
     * make obvious - who was supposed to receive it and what it was about -
     * without needing to unserialise the payload to find out.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('Email permanently failed after all retries', [
            'subject' => $this->subjectLine,
            'view' => $this->viewName,
            'recipients' => array_column($this->to, 'address'),
            'error' => $e->getMessage(),
        ]);
    }
}
