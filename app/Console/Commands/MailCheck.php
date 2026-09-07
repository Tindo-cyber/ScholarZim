<?php

namespace App\Console\Commands;

use App\Mail\ScholarZimMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Answers "is mail actually going to work?" without waiting for a real user to
 * find out that it does not.
 *
 * The failure this exists for took two days to identify. A Mailgun key that no
 * longer authenticated made every send fail with HTTP 401, and nothing said so:
 * EmailService catches \Throwable and returns false, registration does not care
 * whether the mail left, and the only trace was an audit row nobody reads until
 * somebody complains. Configuration that is wrong in a way the application can
 * only discover mid-request is exactly what a deploy-time command should catch.
 *
 * Four things can break, they fail in the same invisible way, and the fix for
 * each is different - so they are reported as four separate stages:
 *
 *   1. Laravel configuration     is a mailer and a credential even present?
 *   2. Mailgun authentication    does the key work, for this domain?
 *   3. Mail submission           does a real message reach Mailgun? (--send)
 *   4. Recipient delivery        did a human get it? NOT PROVABLE FROM HERE.
 *
 * Stage 4 is the one worth being loud about: Mailgun accepting a message means
 * it is queued for delivery, not delivered. A verified domain will happily
 * accept mail addressed to a mailbox that does not exist and drop it later, so
 * "Queued. Thank you." is not evidence anybody received anything.
 *
 * The domain lookup is a GET. Nothing here mutates Mailgun state, and no
 * message is ever sent unless --send is passed explicitly.
 */
class MailCheck extends Command
{
    protected $signature = 'mail:check
                            {--send= : Send a real diagnostic email to this address (submits to Mailgun)}';

    protected $description = 'Report the resolved mail configuration and verify Mailgun connectivity';

    /** Recognisable in a Mailgun log and in whatever inbox it lands in. */
    public const TEST_SUBJECT = 'ScholarZim Mail Diagnostic Test';

    /** Long enough for a cold DNS lookup, short enough to fail a deploy check fast. */
    private const API_TIMEOUT_SECONDS = 15;

    /**
     * The client is injected rather than built here so the suite can drive this
     * against a MockHttpClient. AppServiceProvider binds it to HttpClient::create(),
     * which is the same construction Symfony's Mailgun transport performs - so a
     * TLS or proxy fault that would break real mail breaks this check too, rather
     * than being papered over by a differently-configured client.
     */
    public function __construct(private readonly HttpClientInterface $http)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $mailer = (string) config('mail.default');

        $this->stage('1. Laravel configuration');
        $this->detail('Mailer', $mailer !== '' ? $mailer : '(none)');
        $this->detail('From address', (string) config('mail.from.address'));
        $this->detail('From name', (string) config('mail.from.name'));
        $this->detail('Queue connection', (string) config('queue.default'));

        if ($mailer === '') {
            $this->newLine();
            $this->error('MAIL_MAILER is not set. Nothing can be sent.');

            return self::FAILURE;
        }

        if ($mailer !== 'mailgun') {
            return $this->handleNonMailgunMailer($mailer);
        }

        $domain = (string) config('services.mailgun.domain');
        $secret = (string) config('services.mailgun.secret');
        $endpoint = $this->normaliseEndpoint((string) config('services.mailgun.endpoint'));

        $this->detail('Mailgun domain', $domain !== '' ? $domain : '(not set)');
        $this->detail('Mailgun endpoint', $endpoint);
        // The value is never printed - only whether one is present. A diagnostic
        // that leaks the credential it is checking is worse than no diagnostic:
        // this runs in deploy logs, which are retained and widely readable.
        $this->detail('MAILGUN_DOMAIN', $domain !== '' ? 'configured' : 'NOT CONFIGURED');
        $this->detail('MAILGUN_SECRET', $secret !== '' ? 'configured (value hidden)' : 'NOT CONFIGURED');

        if ($domain === '' || $secret === '') {
            $this->newLine();
            $this->error('Mailgun is selected but its credentials are incomplete.');

            if ($domain === '') {
                $this->line('  Set MAILGUN_DOMAIN to the sending domain registered in Mailgun.');
            }

            if ($secret === '') {
                $this->line('  Set MAILGUN_SECRET to a Mailgun private/sending API key.');
            }

            $this->line('  On Render these are dashboard variables: render.yaml marks both sync: false,');
            $this->line('  so a deploy starts perfectly happily without them and every send fails 401.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->stage('2. Mailgun authentication and domain access');

        if (! $this->checkMailgunDomain($endpoint, $domain, $secret)) {
            return self::FAILURE;
        }

        $recipient = $this->option('send');

        if (! is_string($recipient) || trim($recipient) === '') {
            $this->newLine();
            $this->stage('3. Mail submission');
            $this->line('  Skipped. Pass --send=you@example.com to submit a real diagnostic message.');
            $this->deliveryCaveat();

            return self::SUCCESS;
        }

        return $this->sendDiagnostic(trim($recipient));
    }

    /**
     * smtp, log and array are all legitimate here - local Docker points at
     * MailHog, the test suite uses array - so a non-Mailgun mailer is reported
     * and accepted rather than failed. There is simply no remote credential to
     * verify, and saying so beats a green tick that checked nothing.
     */
    private function handleNonMailgunMailer(string $mailer): int
    {
        $this->newLine();
        $this->stage('2. Mailgun authentication and domain access');
        $this->line("  Skipped: MAIL_MAILER is \"{$mailer}\", not \"mailgun\".");

        if ($mailer === 'smtp') {
            $this->detail('SMTP host', (string) config('mail.mailers.smtp.host'));
            $this->detail('SMTP port', (string) config('mail.mailers.smtp.port'));
        }

        $recipient = $this->option('send');

        if (is_string($recipient) && trim($recipient) !== '') {
            return $this->sendDiagnostic(trim($recipient));
        }

        $this->newLine();
        $this->stage('3. Mail submission');
        $this->line('  Skipped. Pass --send=you@example.com to submit a real diagnostic message.');
        $this->deliveryCaveat();

        return self::SUCCESS;
    }

    /**
     * Read-only GET /v3/domains/{domain}.
     *
     * The status code is the diagnosis, and the codes mean genuinely different
     * things that the application itself cannot tell apart - every one of them
     * surfaces as the same "Unable to send an email" in the log.
     */
    private function checkMailgunDomain(string $endpoint, string $domain, string $secret): bool
    {
        $url = "https://{$endpoint}/v3/domains/" . rawurlencode($domain);

        $this->detail('Request', "GET {$url}");

        try {
            $response = $this->http->request('GET', $url, [
                'auth_basic' => ['api', $secret],
                'timeout' => self::API_TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TimeoutException $e) {
            $this->newLine();
            $this->error('Timed out after ' . self::API_TIMEOUT_SECONDS . 's talking to Mailgun.');
            $this->line('  Mailgun may be unreachable from this host, or blocked by egress rules.');
            $this->line('  Check MAILGUN_ENDPOINT: an EU-region domain must use api.eu.mailgun.net.');

            return false;
        } catch (TransportExceptionInterface $e) {
            // The message is a transport diagnostic (DNS, TLS, proxy). It never
            // contains the credential - that travels in an Authorization header
            // Symfony does not echo back - so it is safe to show, and it is the
            // only thing that distinguishes a CA failure from a dead network.
            $this->newLine();
            $this->error('Could not connect to Mailgun.');
            $this->line('  ' . $e->getMessage());
            $this->line('  Usually DNS, TLS trust or outbound network policy on this host.');

            return false;
        }

        $this->detail('HTTP status', (string) $status);

        return match (true) {
            $status === 200 => $this->reportHealthyDomain($body),
            $status === 401 => $this->reportFailure(
                'Mailgun rejected the credential (401 Unauthorized).',
                [
                    'MAILGUN_SECRET is missing, mistyped, or has been rotated/revoked.',
                    'Generate a fresh key in Mailgun and update it in the platform environment.',
                    'This is the exact failure that reaches the log as "Unable to send an email: Forbidden (code 401)".',
                ]
            ),
            $status === 403 => $this->reportFailure(
                'Mailgun refused the request (403 Forbidden).',
                [
                    'The key authenticated but is not permitted to read this domain.',
                    'A sending-only key, or a key belonging to a different account or subaccount.',
                ]
            ),
            $status === 404 => $this->reportFailure(
                "Mailgun has no domain called \"{$domain}\" (404 Not Found).",
                [
                    'MAILGUN_DOMAIN does not match a domain registered on this account.',
                    'An EU-region domain also 404s here: set MAILGUN_ENDPOINT=api.eu.mailgun.net.',
                ]
            ),
            $status >= 500 => $this->reportFailure(
                "Mailgun returned a server error ({$status}).",
                ['Mailgun-side fault. Configuration is probably fine; retry, then check Mailgun status.']
            ),
            default => $this->reportFailure(
                "Unexpected response from Mailgun ({$status}).",
                [trim(substr($body, 0, 300))]
            ),
        };
    }

    /** A 200 carries the domain's own opinion of itself; it is worth reading out. */
    private function reportHealthyDomain(string $body): bool
    {
        $this->info('  Authenticated. Mailgun recognises this domain.');

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return true;
        }

        $state = $payload['domain']['state'] ?? null;

        if (is_string($state)) {
            $this->detail('Domain state', $state);

            // "unverified" is the quiet one: the API answers 200, the key is
            // fine, and mail is still rejected or spam-foldered downstream.
            if ($state !== 'active') {
                $this->warn("  Domain state is \"{$state}\", not \"active\" - sending may be refused.");
            }
        }

        $records = $payload['sending_dns_records'] ?? null;

        if (! is_array($records) || $records === []) {
            return true;
        }

        $this->newLine();
        $this->line('  Sending DNS records:');

        $invalid = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $valid = (string) ($record['valid'] ?? 'unknown');
            $name = (string) ($record['name'] ?? '');
            $type = (string) ($record['record_type'] ?? '?');

            if ($valid !== 'valid') {
                $invalid++;
            }

            $this->line(sprintf(
                '    [%s] %-5s %s',
                $valid === 'valid' ? 'ok' : '!!',
                $type,
                $name
            ));
        }

        if ($invalid > 0) {
            $this->warn("  {$invalid} sending DNS record(s) are not valid - expect spam-foldering or rejection.");
        }

        return true;
    }

    /**
     * Stage 3, and the only stage that puts a real message on the wire.
     *
     * sendNow(), not send(). ScholarZimMail is ShouldQueue, so send() would
     * write a jobs row and return - proving only that the database works, and
     * reporting success for a mailer that is completely broken. A diagnostic
     * that defers the thing it is diagnosing is worthless, so this bypasses the
     * queue and submits synchronously. The queue path itself is exercised by
     * every real send in production; what needs testing here is the transport.
     *
     * The app's own mailable is reused deliberately: same class, same view, same
     * transport as production mail, so this proves the real path rather than a
     * parallel one built for the test.
     */
    private function sendDiagnostic(string $recipient): int
    {
        $this->newLine();
        $this->stage('3. Mail submission');
        $this->detail('Recipient', $recipient);
        $this->detail('Subject', self::TEST_SUBJECT);

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("  \"{$recipient}\" is not a valid email address.");

            return self::FAILURE;
        }

        $mailable = new ScholarZimMail(
            self::TEST_SUBJECT,
            'emails.notification',
            [
                'type' => 'SYSTEM',
                'notificationMessage' => 'This is a ScholarZim mail diagnostic sent by "php artisan mail:check --send". '
                    . 'It confirms the application can submit mail through its configured transport. '
                    . 'No action is required.',
                'actionUrl' => null,
                'user' => (object) ['full_name' => 'ScholarZim Diagnostic'],
            ]
        );

        try {
            Mail::to($recipient)->sendNow($mailable);
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Submission failed: ' . $e->getMessage());
            $this->line('  This is the same failure a real verification email would hit.');

            return self::FAILURE;
        }

        $this->info('  Submitted. The transport accepted the message without error.');
        $this->deliveryCaveat();

        return self::SUCCESS;
    }

    /**
     * The distinction that makes this command honest.
     *
     * Everything above proves the application can hand a message to Mailgun.
     * None of it proves a person received one, and conflating the two is how a
     * green check mark ends up sitting on top of a silently undelivered inbox.
     */
    private function deliveryCaveat(): void
    {
        $this->newLine();
        $this->stage('4. Recipient delivery');
        $this->line('  NOT VERIFIED - and not verifiable from here.');
        $this->line('  Mailgun accepting a message means it is queued for delivery, not delivered.');
        $this->line('  Confirm in the Mailgun dashboard (Sending -> Logs) that the message was');
        $this->line('  "delivered" rather than dropped, bounced or suppressed.');
    }

    private function reportFailure(string $headline, array $hints): bool
    {
        $this->newLine();
        $this->error($headline);

        foreach ($hints as $hint) {
            if ($hint !== '') {
                $this->line('  ' . $hint);
            }
        }

        return false;
    }

    /**
     * config/services.php stores a bare host ("api.mailgun.net") and a separate
     * scheme, but a hand-set MAILGUN_ENDPOINT very often arrives with the scheme
     * already attached. Both are accepted rather than one silently producing
     * "https://https://api.mailgun.net".
     */
    private function normaliseEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        if ($endpoint === '') {
            return 'api.mailgun.net';
        }

        return rtrim(preg_replace('#^https?://#i', '', $endpoint), '/');
    }

    private function stage(string $title): void
    {
        $this->line('<comment>' . $title . '</comment>');
    }

    private function detail(string $label, string $value): void
    {
        $this->line(sprintf('  %-20s %s', $label . ':', $value));
    }
}
