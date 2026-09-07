<?php

namespace App\Services;

use App\Support\MailgunResult;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one place ScholarZim talks to Mailgun.
 *
 * Every outbound message is submitted here, over Mailgun's HTTP API, by the
 * queue worker. There is no SMTP anywhere in the production path - Render blocks
 * outbound SMTP on its free tier, so a transport that opened a socket to port
 * 587 would hang rather than fail, which is a worse failure than a refusal.
 *
 * This is deliberately not a mail abstraction. It knows nothing about templates,
 * users or notifications - it takes an address, a subject and rendered HTML, and
 * reports what Mailgun said. Rendering stays in ScholarZimMail and the Blade
 * views, so there is exactly one place that decides what an email looks like and
 * exactly one place that knows how to post it.
 *
 * Two rules hold everywhere in this class:
 *
 *   1. The API key is never returned, logged, or embedded in an error. It goes
 *      out in an Authorization header and nowhere else, and anything coming back
 *      from the transport is scrubbed before a caller can see it.
 *
 *   2. Acceptance is not delivery. A 200 from Mailgun means the message was
 *      queued for delivery. Whether a human received it is decided minutes
 *      later and is visible only in Mailgun's own event log.
 */
class MailgunApiService
{
    /** Long enough for a cold TLS handshake, short enough that a worker is not wedged. */
    private const TIMEOUT_SECONDS = 30;

    private const DEFAULT_ENDPOINT = 'api.mailgun.net';

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /** Both values are required; the endpoint has a working default. */
    public function isConfigured(): bool
    {
        return $this->domain() !== '' && $this->secret() !== '';
    }

    public function domain(): string
    {
        return $this->clean(config('services.mailgun.domain'));
    }

    /**
     * Strips what a dashboard paste leaves behind.
     *
     * A platform environment variable is set by typing into a web form, and the
     * three things that survive that unnoticed are a trailing newline, a leading
     * or trailing space, and a pair of quotes copied along with the value from a
     * .env file. None of them are visible when you look at the field afterwards
     * and compare it against your local copy - they compare equal to the eye and
     * produce a flat HTTP 401, which reads as "wrong key" and sends you looking
     * in the wrong place entirely.
     *
     * Quotes are stripped only as a matched surrounding pair, so a value that
     * legitimately contains one is left alone.
     */
    private function clean(mixed $value): string
    {
        $value = trim((string) $value);

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = trim(substr($value, 1, -1));
            }
        }

        return $value;
    }

    /**
     * The host only, never a scheme.
     *
     * config/services.php stores a bare host, but a hand-set MAILGUN_ENDPOINT
     * very often arrives with "https://" already attached. Both are accepted
     * rather than one of them silently building "https://https://api.mailgun.net".
     */
    public function endpoint(): string
    {
        $endpoint = $this->clean(config('services.mailgun.endpoint'));

        if ($endpoint === '') {
            return self::DEFAULT_ENDPOINT;
        }

        return rtrim((string) preg_replace('#^https?://#i', '', $endpoint), '/');
    }

    /** The messages endpoint for the configured domain. */
    public function messagesUrl(): string
    {
        return sprintf('https://%s/v3/%s/messages', $this->endpoint(), rawurlencode($this->domain()));
    }

    /** Read-only domain lookup, used by mail:check. Mutates nothing. */
    public function domainUrl(): string
    {
        return sprintf('https://%s/v3/domains/%s', $this->endpoint(), rawurlencode($this->domain()));
    }

    /**
     * Submit one message.
     *
     * @param string      $toEmail  recipient address
     * @param string|null $toName   display name, if known
     * @param string      $html     the rendered Blade email
     * @param string|null $text     optional plain-text alternative
     */
    public function send(
        string $toEmail,
        ?string $toName,
        string $subject,
        string $html,
        ?string $text = null
    ): MailgunResult {
        if (! $this->isConfigured()) {
            return MailgunResult::failed(
                null,
                'not_configured',
                'MAILGUN_DOMAIN and MAILGUN_SECRET must both be set for the Mailgun API transport.'
            );
        }

        $fields = [
            'from' => $this->formatAddress(
                (string) config('mail.from.address'),
                (string) config('mail.from.name')
            ),
            'to' => $this->formatAddress($toEmail, $toName),
            'subject' => $subject,
            'html' => $html,
        ];

        if ($text !== null && $text !== '') {
            $fields['text'] = $text;
        }

        return $this->post($this->messagesUrl(), $fields);
    }

    /**
     * Read-only account/domain check.
     *
     * Shares the credential handling, endpoint building and error mapping with
     * the send path on purpose: a diagnostic that authenticates differently from
     * the thing it is diagnosing can pass while real mail fails.
     */
    public function fetchDomain(): MailgunResult
    {
        if (! $this->isConfigured()) {
            return MailgunResult::failed(
                null,
                'not_configured',
                'MAILGUN_DOMAIN and MAILGUN_SECRET must both be set.'
            );
        }

        return $this->request('GET', $this->domainUrl(), []);
    }

    private function post(string $url, array $fields): MailgunResult
    {
        return $this->request('POST', $url, $fields);
    }

    /**
     * One request, one result, no exceptions escaping.
     *
     * Failures are returned rather than thrown so the caller decides what a
     * failure means - the queue worker rethrows to trigger a retry, mail:check
     * prints and exits non-zero. Swallowing is never one of the options: every
     * path here produces either an accepted result or a described failure.
     */
    private function request(string $method, string $url, array $fields): MailgunResult
    {
        $options = [
            // Basic auth with the literal username "api"; Mailgun's private and
            // sending keys both authenticate this way. Symfony puts this in an
            // Authorization header, which is not echoed back in its exceptions.
            'auth_basic' => ['api', $this->secret()],
            'timeout' => self::TIMEOUT_SECONDS,
        ];

        if ($fields !== []) {
            $options['body'] = $fields;
        }

        try {
            $response = $this->http->request($method, $url, $options);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TimeoutException $e) {
            return MailgunResult::failed(
                null,
                'timeout',
                'Timed out after ' . self::TIMEOUT_SECONDS . 's contacting Mailgun. '
                . 'Check outbound network access and that MAILGUN_ENDPOINT matches the domain region.'
            );
        } catch (TransportExceptionInterface $e) {
            return MailgunResult::failed(null, 'connection_failed', $this->redact($e->getMessage()));
        }

        return $this->interpret($status, $body);
    }

    /**
     * Mailgun's status codes mean genuinely different things, and the fix for
     * each is different. From inside the application they are indistinguishable
     * - every one of them used to surface as the same "Unable to send an email"
     * - so they are separated here, once, where the response is still intact.
     */
    private function interpret(int $status, string $body): MailgunResult
    {
        $decoded = json_decode($body, true);
        $payload = is_array($decoded) ? $decoded : null;

        // 200 is what /messages actually answers on success ("Queued. Thank
        // you."); 202 is accepted here too rather than being treated as an
        // unexpected code, since it carries the same meaning.
        if ($status === 200 || $status === 202) {
            $id = $payload['id'] ?? null;

            return MailgunResult::accepted($status, is_string($id) ? trim($id, '<>') : null, $payload);
        }

        $message = $this->messageFrom($payload, $body);

        return match (true) {
            $status === 401 => MailgunResult::failed(
                $status,
                'unauthorized',
                'Mailgun rejected the credential. MAILGUN_SECRET is missing, mistyped, or has been rotated. '
                . $message
            ),
            $status === 403 => MailgunResult::failed(
                $status,
                'forbidden',
                'The key authenticated but is not permitted to use this domain - a sending-only key, '
                . 'or one from another account or subaccount. ' . $message
            ),
            $status === 404 => MailgunResult::failed(
                $status,
                'not_found',
                'Mailgun has no such domain. Check MAILGUN_DOMAIN, and note that an EU-region domain '
                . 'needs MAILGUN_ENDPOINT=api.eu.mailgun.net. ' . $message
            ),
            $status === 400 || $status === 422 => MailgunResult::failed(
                $status,
                'invalid_request',
                'Mailgun rejected the message itself - usually a malformed address or a missing field. '
                . $message
            ),
            $status === 429 => MailgunResult::failed(
                $status,
                'rate_limited',
                'Rate limited by Mailgun. The queue will retry with backoff. ' . $message
            ),
            $status >= 500 => MailgunResult::failed(
                $status,
                'server_error',
                'Mailgun-side failure. Configuration is probably fine; the queue will retry. ' . $message
            ),
            default => MailgunResult::failed($status, 'unexpected_status', $message),
        };
    }

    /** Mailgun puts its explanation in "message"; fall back to a clipped body. */
    private function messageFrom(?array $payload, string $body): string
    {
        $message = $payload['message'] ?? null;

        if (is_string($message) && $message !== '') {
            return $this->redact($message);
        }

        return $this->redact(trim(substr($body, 0, 200)));
    }

    /**
     * Belt and braces.
     *
     * Symfony does not put the Authorization header into its exception
     * messages, so in practice nothing reaching here contains the key. This
     * guarantees it anyway, because the cost of being wrong once - a credential
     * written into a retained production log - is not recoverable by fixing the
     * code afterwards.
     */
    private function redact(string $message): string
    {
        $secret = $this->secret();

        if ($secret === '') {
            return $message;
        }

        return str_replace($secret, '[redacted]', $message);
    }

    private function secret(): string
    {
        return $this->clean(config('services.mailgun.secret'));
    }

    /**
     * A comparable, non-reversible description of the configured credential.
     *
     * Exists so a production instance can be asked "is your key the same one
     * that works locally?" without the key being printed, transmitted or
     * written anywhere. Length is what catches the invisible faults - a stray
     * quote or newline changes it - and the hash prefix confirms the rest
     * without being invertible for a 50-character random key.
     *
     * @return array<string, mixed>
     */
    public function credentialFingerprint(): array
    {
        $secret = $this->secret();
        $raw = (string) config('services.mailgun.secret');

        return [
            'configured' => $secret !== '',
            'length' => strlen($secret),
            'sha256_prefix' => $secret === '' ? null : substr(hash('sha256', $secret), 0, 12),
            // True when cleaning actually changed something - i.e. the stored
            // value carried surrounding whitespace or quotes.
            'needed_cleaning' => $secret !== $raw,
        ];
    }

    private function formatAddress(string $email, ?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return $email;
        }

        // Quote the display name: an unescaped comma or angle bracket in a real
        // person's name would otherwise be parsed as another recipient.
        return sprintf('"%s" <%s>', str_replace(['"', '\\'], '', $name), $email);
    }
}
