<?php

namespace Tests\Feature;

use App\Console\Commands\MailCheck;
use App\Mail\ScholarZimMail;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\TestCase;

/**
 * mail:check exists so a broken mailer is found at deploy time rather than by a
 * user who never receives a verification link. These tests hold it to the two
 * properties that make it worth trusting: the exit code has to be honest, so CI
 * and a deploy script can act on it, and the credential must never appear in
 * output that lands in a retained log.
 *
 * Every case drives a MockHttpClient. Nothing here reaches Mailgun, so the suite
 * neither needs an account nor breaks when one expires.
 *
 * Assertions run against the captured output rather than through
 * expectsOutputToContain(). That helper matches each substring against a single
 * write and consumes writes in the order the expectations were declared, so
 * overlapping strings - "mailgun" and "api.mailgun.net", which is most of what
 * this command prints - silently swallow one another.
 */
class MailCheckCommandTest extends TestCase
{
    /** Distinctive, and obviously not a real key, so a leak assertion is unambiguous. */
    private const FAKE_SECRET = 'test-secret-must-never-be-printed-9f8e7d6c';

    private const FAKE_DOMAIN = 'mail.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'mailgun',
            'mail.from.address' => 'noreply@scholarzim.co.zw',
            'mail.from.name' => 'ScholarZim',
            'services.mailgun.domain' => self::FAKE_DOMAIN,
            'services.mailgun.secret' => self::FAKE_SECRET,
            'services.mailgun.endpoint' => 'api.mailgun.net',
        ]);
    }

    // ------------------------------------------------------- configuration --

    public function test_it_reports_the_resolved_configuration(): void
    {
        $this->fakeHttp($this->domainResponse());

        [$exit, $output] = $this->runCheck();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Mailer:', $output);
        $this->assertStringContainsString('mailgun', $output);
        $this->assertStringContainsString(self::FAKE_DOMAIN, $output);
        $this->assertStringContainsString('api.mailgun.net', $output);
        $this->assertStringContainsString('noreply@scholarzim.co.zw', $output);
    }

    public function test_it_states_that_both_mailgun_values_are_configured(): void
    {
        $this->fakeHttp($this->domainResponse());

        [$exit, $output] = $this->runCheck();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('MAILGUN_DOMAIN:', $output);
        $this->assertStringContainsString('MAILGUN_SECRET:', $output);
        $this->assertStringContainsString('configured (value hidden)', $output);
    }

    public function test_a_missing_mailgun_domain_fails_the_check(): void
    {
        config(['services.mailgun.domain' => '']);
        $this->fakeHttp($this->neverCalled());

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('NOT CONFIGURED', $output);
        $this->assertStringContainsString('Set MAILGUN_DOMAIN', $output);
    }

    public function test_a_missing_mailgun_secret_fails_the_check(): void
    {
        config(['services.mailgun.secret' => '']);
        $this->fakeHttp($this->neverCalled());

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('NOT CONFIGURED', $output);
        $this->assertStringContainsString('Set MAILGUN_SECRET', $output);
    }

    /**
     * Incomplete credentials must never reach the network. Beyond being wasteful
     * it would report a connection error for what is really a missing variable,
     * which is the wrong thing to go and fix.
     */
    public function test_it_does_not_call_mailgun_when_configuration_is_incomplete(): void
    {
        config(['services.mailgun.secret' => '']);

        $client = $this->neverCalled();
        $this->fakeHttp($client);

        [$exit] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertSame(0, $client->getRequestsCount());
    }

    // ---------------------------------------------------- Mailgun API paths --

    public function test_a_successful_domain_lookup_passes_and_reports_state_and_dns(): void
    {
        $this->fakeHttp($this->domainResponse());

        [$exit, $output] = $this->runCheck();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Authenticated. Mailgun recognises this domain.', $output);
        $this->assertStringContainsString('Domain state:', $output);
        $this->assertStringContainsString('active', $output);
        $this->assertStringContainsString('Sending DNS records:', $output);
    }

    public function test_it_warns_when_sending_dns_records_are_not_valid(): void
    {
        $this->fakeHttp(new MockHttpClient(new MockResponse((string) json_encode([
            'domain' => ['name' => self::FAKE_DOMAIN, 'state' => 'unverified'],
            'sending_dns_records' => [
                ['record_type' => 'TXT', 'name' => self::FAKE_DOMAIN, 'valid' => 'unknown'],
            ],
        ]), ['http_code' => 200])));

        [$exit, $output] = $this->runCheck();

        // Still exit 0: the credential works. DNS is a warning, not a failure,
        // and a deploy check that fails on it would block a rollout over a
        // record that is often "unknown" simply because it was checked recently.
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('not "active"', $output);
        $this->assertStringContainsString('sending DNS record(s) are not valid', $output);
    }

    public function test_a_401_is_reported_as_an_authentication_failure(): void
    {
        $this->fakeHttp(new MockHttpClient(new MockResponse('Forbidden', ['http_code' => 401])));

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('401 Unauthorized', $output);
        $this->assertStringContainsString('rotated/revoked', $output);
    }

    public function test_a_403_is_reported_separately_from_a_401(): void
    {
        $this->fakeHttp(new MockHttpClient(new MockResponse('Forbidden', ['http_code' => 403])));

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('403 Forbidden', $output);
        $this->assertStringContainsString('not permitted to read this domain', $output);
    }

    public function test_a_404_is_reported_as_a_wrong_domain(): void
    {
        $this->fakeHttp(new MockHttpClient(new MockResponse('Not Found', ['http_code' => 404])));

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('404 Not Found', $output);
        $this->assertStringContainsString('api.eu.mailgun.net', $output);
    }

    public function test_a_server_error_is_attributed_to_mailgun_not_to_configuration(): void
    {
        $this->fakeHttp(new MockHttpClient(new MockResponse('boom', ['http_code' => 503])));

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Mailgun-side fault', $output);
    }

    public function test_a_timeout_is_reported_as_a_timeout(): void
    {
        $this->fakeHttp(new MockHttpClient(function (): MockResponse {
            throw new TimeoutException('Idle timeout reached');
        }));

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Timed out', $output);
        $this->assertStringContainsString('api.eu.mailgun.net', $output);
    }

    public function test_a_connection_failure_is_reported_as_a_connection_failure(): void
    {
        $this->fakeHttp(new MockHttpClient(function (): MockResponse {
            throw new TransportException('Could not resolve host: api.mailgun.net');
        }));

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Could not connect to Mailgun', $output);
        $this->assertStringContainsString('Could not resolve host', $output);
    }

    // ------------------------------------------------------------ --send  --

    /**
     * Mail::fake() is what keeps this from putting a real message on the wire.
     *
     * The command uses sendNow() rather than send(). ScholarZimMail is
     * ShouldQueue, so send() would write a jobs row and return - proving the
     * database works and reporting success for a completely broken transport -
     * so this asserts the message was *sent*, not queued.
     */
    public function test_the_send_option_submits_through_the_application_mailable(): void
    {
        Mail::fake();
        $this->fakeHttp($this->domainResponse());

        [$exit, $output] = $this->runCheck(['--send' => 'someone@example.test']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Submitted.', $output);
        $this->assertStringContainsString(MailCheck::TEST_SUBJECT, $output);

        Mail::assertSent(ScholarZimMail::class, function (ScholarZimMail $mail) {
            // The fake captures the mailable before Laravel calls build(), and
            // build() is where ScholarZimMail moves its constructor argument
            // onto the Mailable's own $subject - so without this the subject
            // assertion would read a null that says nothing about the message.
            $mail->build();

            return $mail->hasTo('someone@example.test')
                && $mail->hasSubject(MailCheck::TEST_SUBJECT);
        });

        Mail::assertNothingQueued();
    }

    public function test_nothing_is_sent_when_the_send_option_is_absent(): void
    {
        Mail::fake();
        $this->fakeHttp($this->domainResponse());

        [$exit] = $this->runCheck();

        $this->assertSame(0, $exit);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_an_invalid_send_address_fails_before_anything_is_sent(): void
    {
        Mail::fake();
        $this->fakeHttp($this->domainResponse());

        [$exit, $output] = $this->runCheck(['--send' => 'not-an-address']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('is not a valid email address', $output);
        Mail::assertNothingSent();
    }

    /**
     * A transport that throws must fail the command. Silently returning 0 here
     * would reproduce the exact fault this command was written to expose.
     */
    public function test_a_failing_transport_makes_the_send_fail(): void
    {
        $this->fakeHttp($this->domainResponse());

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('Unable to send an email: Forbidden (code 401).'));

        [$exit, $output] = $this->runCheck(['--send' => 'someone@example.test']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Submission failed', $output);
    }

    // ------------------------------------------------------------ contract --

    /**
     * The four stages are the point of the command: Mailgun accepting a message
     * is not delivery, and a diagnostic that blurs the two is how a green tick
     * ends up sitting on an inbox that never received anything.
     */
    public function test_it_distinguishes_submission_from_recipient_delivery(): void
    {
        $this->fakeHttp($this->domainResponse());

        [$exit, $output] = $this->runCheck();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1. Laravel configuration', $output);
        $this->assertStringContainsString('2. Mailgun authentication and domain access', $output);
        $this->assertStringContainsString('3. Mail submission', $output);
        $this->assertStringContainsString('4. Recipient delivery', $output);
        $this->assertStringContainsString('NOT VERIFIED', $output);
    }

    /**
     * The whole reason the command reports "configured" instead of the value.
     * This runs in deploy logs, which are retained and widely readable.
     */
    public function test_the_mailgun_secret_never_appears_in_the_output(): void
    {
        Mail::fake();
        $this->fakeHttp($this->domainResponse());

        [, $output] = $this->runCheck(['--send' => 'someone@example.test']);

        $this->assertStringNotContainsString(self::FAKE_SECRET, $output);
    }

    public function test_the_mailgun_secret_never_appears_when_the_credential_is_rejected(): void
    {
        $this->fakeHttp(new MockHttpClient(new MockResponse('Forbidden', ['http_code' => 401])));

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString(self::FAKE_SECRET, $output);
        $this->assertStringContainsString('401', $output);
    }

    // ------------------------------------------------- non-mailgun mailers --

    /**
     * Local Docker points at MailHog and the suite uses the array transport.
     * Neither has a remote credential to verify, so both are reported and
     * accepted rather than failed.
     */
    public function test_a_non_mailgun_mailer_is_reported_and_passes(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mailhog',
            'mail.mailers.smtp.port' => 1025,
        ]);
        $this->fakeHttp($this->neverCalled());

        [$exit, $output] = $this->runCheck();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('not "mailgun"', $output);
        $this->assertStringContainsString('mailhog', $output);
    }

    public function test_an_unset_mailer_fails(): void
    {
        config(['mail.default' => '']);
        $this->fakeHttp($this->neverCalled());

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('MAIL_MAILER is not set', $output);
    }

    // ------------------------------------------------------------- helpers --

    /**
     * Console commands are constructed when the Artisan application first boots,
     * which has usually already happened by the time a test binds anything. So
     * the instance is re-registered after the binding, otherwise the command
     * would hold the real HttpClient and the mock would go unused.
     */
    private function fakeHttp(HttpClientInterface $client): void
    {
        $this->app->instance(HttpClientInterface::class, $client);
        $this->app->make(ConsoleKernel::class)->registerCommand($this->app->make(MailCheck::class));
    }

    /** @return array{0: int, 1: string} exit code and captured output */
    private function runCheck(array $parameters = []): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);

        $exit = $kernel->call('mail:check', $parameters);

        return [$exit, $kernel->output()];
    }

    private function domainResponse(): MockHttpClient
    {
        return new MockHttpClient(new MockResponse((string) json_encode([
            'domain' => ['name' => self::FAKE_DOMAIN, 'state' => 'active'],
            'sending_dns_records' => [
                ['record_type' => 'TXT', 'name' => self::FAKE_DOMAIN, 'valid' => 'valid'],
                ['record_type' => 'CNAME', 'name' => 'email.' . self::FAKE_DOMAIN, 'valid' => 'valid'],
            ],
        ]), ['http_code' => 200]));
    }

    private function neverCalled(): MockHttpClient
    {
        return new MockHttpClient(function (): MockResponse {
            throw new \LogicException('mail:check made an unexpected HTTP request.');
        });
    }
}
