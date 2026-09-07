<?php

namespace App\Console\Commands;

use App\Mail\ScholarZimMail;
use App\Services\MailgunApiService;
use App\Support\MailgunResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

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
 * Everything here goes through MailgunApiService - the same class, credentials,
 * URL and error mapping the queue worker uses in production. A diagnostic with
 * its own HTTP path can pass while real mail fails, which makes it worse than
 * having no diagnostic at all.
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

    public function __construct(private readonly MailgunApiService $mailgun)
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

        $domain = $this->mailgun->domain();

        $this->detail('Delivery path', 'Mailgun HTTP API (no SMTP)');
        $this->detail('Mailgun domain', $domain !== '' ? $domain : '(not set)');
        $this->detail('Mailgun endpoint', $this->mailgun->endpoint());
        // The value is never printed - only whether one is present. A diagnostic
        // that leaks the credential it is checking is worse than no diagnostic:
        // this runs in deploy logs, which are retained and widely readable.
        $this->detail('MAILGUN_DOMAIN', $domain !== '' ? 'configured' : 'NOT CONFIGURED');
        $this->detail('MAILGUN_SECRET', $this->secretConfigured() ? 'configured (value hidden)' : 'NOT CONFIGURED');

        if (! $this->mailgun->isConfigured()) {
            $this->newLine();
            $this->error('Mailgun is selected but its credentials are incomplete.');

            if ($domain === '') {
                $this->line('  Set MAILGUN_DOMAIN to the sending domain registered in Mailgun.');
            }

            if (! $this->secretConfigured()) {
                $this->line('  Set MAILGUN_SECRET to a Mailgun private/sending API key.');
            }

            $this->line('  On Render these are dashboard variables: render.yaml marks both sync: false,');
            $this->line('  so a deploy starts perfectly happily without them and every send fails 401.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->stage('2. Mailgun authentication and domain access');

        if (! $this->checkMailgunDomain()) {
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
     * and accepted rather than failed. ScholarZimMail defers to Laravel's own
     * transport in that case, so there is no Mailgun credential to verify.
     */
    private function handleNonMailgunMailer(string $mailer): int
    {
        $this->detail('Delivery path', "Laravel \"{$mailer}\" transport (Mailgun API not in use)");

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
     * Read-only GET /v3/domains/{domain}, through the production service.
     *
     * The status code is the diagnosis, and the codes mean genuinely different
     * things that the application itself cannot tell apart - every one of them
     * used to surface as the same "Unable to send an email" in the log.
     * MailgunApiService owns that mapping so the worker and this command cannot
     * disagree about what a 403 means.
     */
    private function checkMailgunDomain(): bool
    {
        $this->detail('Request', 'GET ' . $this->mailgun->domainUrl());

        $result = $this->mailgun->fetchDomain();

        $this->detail('HTTP status', $result->status === null ? '(no response)' : (string) $result->status);

        if (! $result->success) {
            return $this->reportFailure($result);
        }

        $this->info('  Authenticated. Mailgun recognises this domain.');

        return $this->reportDomainDetails($result->payload);
    }

    /** A 200 carries the domain's own opinion of itself; it is worth reading out. */
    private function reportDomainDetails(?array $payload): bool
    {
        if ($payload === null) {
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

            if ($valid !== 'valid') {
                $invalid++;
            }

            $this->line(sprintf(
                '    [%s] %-5s %s',
                $valid === 'valid' ? 'ok' : '!!',
                (string) ($record['record_type'] ?? '?'),
                (string) ($record['name'] ?? '')
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
     * The mailable is built and rendered exactly as production builds and
     * renders it, then handed to the same MailgunApiService the queue worker
     * uses. What this skips is only the queue hop: a diagnostic that wrote a
     * jobs row and returned would prove the database works and report success
     * for a completely broken transport. Rendering and submission - the
     * interesting half - stay identical to the production path.
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
            $html = $mailable->render();
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('The email template failed to render: ' . $e->getMessage());
            $this->line('  Nothing was submitted. This is a Blade fault, not a Mailgun one.');

            return self::FAILURE;
        }

        // A non-Mailgun mailer is a real, wanted setup (MailHog locally), and a
        // diagnostic for it has to exercise that transport rather than posting
        // local test mail to a live provider.
        if (config('mail.default') !== 'mailgun') {
            return $this->sendThroughLaravelTransport($mailable, $recipient);
        }

        $result = $this->mailgun->send($recipient, null, self::TEST_SUBJECT, $html);

        $this->detail('HTTP status', $result->status === null ? '(no response)' : (string) $result->status);

        if (! $result->success) {
            $this->reportFailure($result);
            $this->line('  This is the same failure a real verification email would hit.');

            return self::FAILURE;
        }

        $this->detail('Mailgun message id', $result->messageId ?? '(not returned)');
        $this->info('  Submitted. Mailgun accepted the message.');
        $this->deliveryCaveat();

        return self::SUCCESS;
    }

    /**
     * The MailHog / log / array path.
     *
     * sendNow(), not send(): ScholarZimMail is ShouldQueue, so send() would
     * write a jobs row and report success without the transport being touched.
     */
    private function sendThroughLaravelTransport(ScholarZimMail $mailable, string $recipient): int
    {
        try {
            Mail::to($recipient)->sendNow($mailable);
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Submission failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('  Submitted through the Laravel "' . config('mail.default') . '" transport.');
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

    /**
     * MailgunResult carries a safe, already-redacted explanation; the hints
     * below turn it into something someone can act on without knowing the API.
     */
    private function reportFailure(MailgunResult $result): bool
    {
        $this->newLine();
        $this->error(sprintf('Mailgun request failed (%s).', $result->reason));
        $this->line('  ' . (string) $result->error);

        foreach ($this->hintsFor($result->reason) as $hint) {
            $this->line('  ' . $hint);
        }

        return false;
    }

    /** @return array<int, string> */
    private function hintsFor(string $reason): array
    {
        return match ($reason) {
            'unauthorized' => [
                'This is the exact failure that reaches the log as "Forbidden (code 401)".',
                'Check MAILGUN_DOMAIN first: a domain that is not on the account returns 401 on send,',
                'which is indistinguishable from a bad key. Stage 2 above answers 404 when that is the cause.',
                'If the domain is right, generate a fresh key in Mailgun and update the platform environment.',
            ],
            'not_found' => ['Check MAILGUN_DOMAIN against the domains listed in the Mailgun dashboard.'],
            'timeout', 'connection_failed' => [
                'Usually DNS, TLS trust or outbound network policy on this host.',
            ],
            'rate_limited' => ['Queued mail will retry with backoff; no configuration change is needed.'],
            'server_error' => ['Retry, then check Mailgun status before changing anything here.'],
            default => [],
        };
    }

    private function secretConfigured(): bool
    {
        return filled(config('services.mailgun.secret'));
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
