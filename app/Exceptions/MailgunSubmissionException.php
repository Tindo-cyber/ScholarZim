<?php

namespace App\Exceptions;

use App\Support\MailgunResult;

/**
 * Mailgun refused a message.
 *
 * Thrown from ScholarZimMail::send() rather than returned, because the queue
 * only retries a job that fails - returning a falsy value would make the retry
 * policy and its backoff decorative.
 *
 * It exists as its own type so the failure can be audited exactly once. Under
 * the database queue the failure happens inside the worker, where
 * ScholarZimMail::failed() records it. Under the sync driver the same exception
 * then keeps travelling up into EmailService's catch, which audits everything it
 * sees - so without a way to recognise an already-audited failure, one refused
 * email produced two EMAIL_DELIVERY_FAILED rows and the trail implied two lost
 * messages.
 */
class MailgunSubmissionException extends \RuntimeException
{
    public function __construct(
        public readonly string $recipient,
        public readonly MailgunResult $result,
    ) {
        parent::__construct(sprintf(
            'Mailgun submission failed for %s: %s',
            $recipient,
            $result->summary()
        ));
    }
}
