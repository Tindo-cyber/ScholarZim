<?php

namespace App\Support;

/**
 * The outcome of one submission to Mailgun's HTTP API.
 *
 * `$success` means Mailgun accepted the message for delivery. It does **not**
 * mean anybody received it: Mailgun answers "Queued. Thank you." to a perfectly
 * well-formed message addressed to a mailbox that does not exist, and only
 * decides that later. Every consumer of this object has to keep those two facts
 * apart, because conflating them is how an application ends up reporting success
 * over an inbox that stayed empty.
 *
 * `$error` is safe to log and safe to show. It never carries the API key: the
 * credential travels in an Authorization header, which is never copied into
 * this object, and MailgunApiService redacts the secret from any transport
 * message before it gets here.
 */
class MailgunResult
{
    /**
     * @param array<string, mixed>|null $payload the decoded response body, when
     *        Mailgun sent one. Carried on the result rather than cached on the
     *        service: a service-level cache is shared by every call, so one
     *        send would quietly overwrite the domain details a diagnostic was
     *        about to read.
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?int $status,
        public readonly ?string $messageId,
        public readonly ?string $error,
        public readonly string $reason,
        public readonly ?array $payload = null,
    ) {
    }

    /** Mailgun accepted the message. Delivery is a separate, later question. */
    public static function accepted(int $status, ?string $messageId, ?array $payload = null): self
    {
        return new self(true, $status, $messageId, null, 'accepted', $payload);
    }

    public static function failed(?int $status, string $reason, string $error): self
    {
        return new self(false, $status, null, $error, $reason);
    }

    /** One line for a log or an audit row. Never contains the credential. */
    public function summary(): string
    {
        return $this->success
            ? sprintf('accepted (HTTP %d%s)', $this->status, $this->messageId ? ', id ' . $this->messageId : '')
            : sprintf(
                '%s (HTTP %s): %s',
                $this->reason,
                $this->status === null ? 'none' : (string) $this->status,
                (string) $this->error
            );
    }
}
