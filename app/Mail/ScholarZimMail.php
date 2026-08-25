<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

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

    public int $backoff = 60;

    /**
     * Property names avoid Mailable's own $view / $subject, which are not
     * readonly on the parent and cannot be redeclared as such.
     */
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $viewName,
        private readonly array $payload,
    ) {
    }

    public function build(): self
    {
        return $this->subject($this->subjectLine)->view($this->viewName, $this->payload);
    }
}
