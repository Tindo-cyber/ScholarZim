<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EmailService;
use App\Support\AuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\TestCase;

/**
 * The whole path, end to end, with only the network faked.
 *
 * EmailService -> queued ScholarZimMail -> worker -> MailgunApiService -> HTTP.
 * The tests below assert the properties that would be catastrophic to get wrong
 * and invisible if they were: that one email produces exactly one Mailgun
 * submission, that Laravel's own transport delivers nothing, that a refusal is
 * neither swallowed nor double-counted, and that the credential never reaches a
 * log.
 *
 * QUEUE_CONNECTION is sync here, so the job runs inline and the whole chain
 * executes within the test. That is the same code the worker runs; only the
 * hand-off differs.
 */
class MailgunDeliveryPathTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_SECRET = 'test-secret-must-never-be-printed-9f8e7d6c';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'mailgun',
            'mail.from.address' => 'noreply@scholarzim.co.zw',
            'mail.from.name' => 'ScholarZim',
            'services.mailgun.domain' => 'mail.example.test',
            'services.mailgun.secret' => self::FAKE_SECRET,
            'services.mailgun.endpoint' => 'api.mailgun.net',
        ]);
    }

    /**
     * The one that matters most.
     *
     * Two submissions per email would double every notification the platform
     * sends, and the older architecture had a real route to it: a mailable that
     * both queued itself and handed a message to a transport.
     */
    public function test_one_queued_email_makes_exactly_one_mailgun_request(): void
    {
        $requests = [];

        $this->fakeHttp($requests);

        $sent = app(EmailService::class)->sendNotification(
            $this->user(),
            'NEW_APPLICATION',
            'Someone applied to your scholarship.'
        );

        $this->assertTrue($sent);
        $this->assertCount(1, $requests, 'exactly one Mailgun submission per queued email');
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('https://api.mailgun.net/v3/mail.example.test/messages', $requests[0]['url']);
    }

    /** Every public entry point must reach Mailgun, not just notifications. */
    public function test_every_email_service_method_submits_through_the_api(): void
    {
        foreach ([
            'verification' => fn (EmailService $s, User $u) => $s->sendEmailVerification($u, 'token-123'),
            'password reset' => fn (EmailService $s, User $u) => $s->sendPasswordReset($u, 'token-456'),
            'welcome' => fn (EmailService $s, User $u) => $s->sendWelcome($u),
            'notification' => fn (EmailService $s, User $u) => $s->sendNotification($u, 'PROVIDER_APPROVED', 'Hello.'),
        ] as $label => $call) {
            $requests = [];
            $this->fakeHttp($requests);

            $this->assertTrue($call(app(EmailService::class), $this->user()), $label . ' should be accepted');
            $this->assertCount(1, $requests, $label . ' should make one Mailgun request');
        }
    }

    /**
     * The rendered Blade view is what gets submitted - not a second, parallel
     * template built for the API. The distinctive sentence proves the real view
     * rendered, and that the $message collision has not come back.
     */
    public function test_the_rendered_blade_html_is_what_is_submitted(): void
    {
        $requests = [];
        $this->fakeHttp($requests);

        app(EmailService::class)->sendNotification(
            $this->user(),
            'NEW_APPLICATION',
            'A distinctive sentence no other email view would contain.'
        );

        parse_str((string) $requests[0]['body'], $fields);

        $this->assertStringContainsString(
            'A distinctive sentence no other email view would contain.',
            $fields['html']
        );
        $this->assertStringNotContainsString('Illuminate\\Mail\\Message', $fields['html']);
        $this->assertSame('You received a new application', $fields['subject']);
        $this->assertStringContainsString('noreply@scholarzim.co.zw', $fields['from']);
    }

    /**
     * Laravel's mail transport must deliver nothing. Mail::to() is used only to
     * enqueue: for a ShouldQueue mailable it dispatches and returns, and the
     * array transport it would otherwise have written to stays empty.
     */
    public function test_laravel_transport_delivers_nothing(): void
    {
        // An ArrayTransport is substituted for whatever the mailgun mailer would
        // have resolved, purely as a tripwire: if ScholarZimMail ever fell back
        // to parent::send(), the message would land here instead of at Mailgun,
        // and the count below would not be zero.
        $tripwire = new ArrayTransport();
        app('mailer')->setSymfonyTransport($tripwire);

        $requests = [];
        $this->fakeHttp($requests);

        app(EmailService::class)->sendNotification($this->user(), 'PROVIDER_APPROVED', 'Hello.');

        $this->assertCount(1, $requests, 'the message should have gone to Mailgun');
        $this->assertCount(
            0,
            $tripwire->messages(),
            "Laravel's own transport must never carry a message"
        );
    }

    // ------------------------------------------------------------ failures --

    public function test_a_refused_message_is_reported_as_a_failure_not_swallowed(): void
    {
        $this->fakeHttpResponding(401, '{"message":"Forbidden"}');

        $sent = app(EmailService::class)->sendEmailVerification($this->user(), 'token-123');

        $this->assertFalse($sent, 'a 401 must not be reported as a sent email');
    }

    /** A failed verification email must not leave an audit row claiming it was sent. */
    public function test_a_refused_verification_is_not_audited_as_sent(): void
    {
        $this->fakeHttpResponding(401, '{"message":"Forbidden"}');

        app(EmailService::class)->sendEmailVerification($this->user(), 'token-123');

        $this->assertSame(
            0,
            DB::table('audit_log')->where('action', AuditAction::EMAIL_VERIFICATION_SENT)->count()
        );
    }

    public function test_a_refused_message_is_audited_exactly_once(): void
    {
        $this->fakeHttpResponding(401, '{"message":"Forbidden"}');

        app(EmailService::class)->sendNotification($this->user(), 'PROVIDER_APPROVED', 'Hello.');

        $rows = DB::table('audit_log')->where('action', AuditAction::EMAIL_DELIVERY_FAILED)->get();

        $this->assertCount(1, $rows, 'one refused email must leave one audit row, not one per layer');
        $this->assertStringContainsString('401', (string) $rows[0]->details);
    }

    /** The status has to survive into the log, or the outage is invisible again. */
    public function test_the_failure_log_carries_the_status_and_reason(): void
    {
        $entries = [];
        Log::listen(function ($entry) use (&$entries) {
            $entries[] = $entry;
        });

        $this->fakeHttpResponding(404, '{"message":"Domain not found"}');

        app(EmailService::class)->sendNotification($this->user(), 'PROVIDER_APPROVED', 'Hello.');

        $rejected = collect($entries)->firstWhere('message', 'Mailgun rejected an email');

        $this->assertNotNull($rejected, 'a refusal must be logged');
        $this->assertSame(404, $rejected->context['status']);
        $this->assertSame('not_found', $rejected->context['reason']);
        $this->assertSame('nobody@example.test', $rejected->context['to']);
    }

    public function test_no_log_entry_contains_the_mailgun_secret(): void
    {
        $entries = [];
        Log::listen(function ($entry) use (&$entries) {
            $entries[] = $entry;
        });

        $this->fakeHttpResponding(401, '{"message":"Forbidden"}');

        app(EmailService::class)->sendNotification($this->user(), 'PROVIDER_APPROVED', 'Hello.');

        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString(
                self::FAKE_SECRET,
                $entry->message . ' ' . json_encode($entry->context)
            );
        }
    }

    /** Missing credentials must fail locally rather than as a mystery HTTP error. */
    public function test_missing_credentials_fail_without_touching_the_network(): void
    {
        config(['services.mailgun.secret' => '']);

        $requests = [];
        $this->fakeHttp($requests);

        $sent = app(EmailService::class)->sendNotification($this->user(), 'PROVIDER_APPROVED', 'Hello.');

        $this->assertFalse($sent);
        $this->assertCount(0, $requests);
    }

    // --------------------------------------------------- other transports --

    /**
     * `docker compose up` points MAIL_MAILER at the bundled MailHog. Routing
     * that through the Mailgun API would post local development mail to a live
     * provider from every developer's laptop.
     */
    public function test_a_non_mailgun_mailer_still_uses_the_laravel_transport(): void
    {
        config(['mail.default' => 'array']);

        $requests = [];
        $this->fakeHttp($requests);

        app(EmailService::class)->sendNotification($this->user(), 'PROVIDER_APPROVED', 'Hello.');

        $this->assertCount(0, $requests, 'a non-Mailgun mailer must not reach the Mailgun API');
        $this->assertGreaterThan(
            0,
            count(app('mailer')->getSymfonyTransport()->messages()),
            'the configured Laravel transport should have carried it instead'
        );
    }

    /** Mail::fake() must still intercept, so existing suites keep working. */
    public function test_the_mail_fake_still_intercepts_before_any_request(): void
    {
        Mail::fake();

        $requests = [];
        $this->fakeHttp($requests);

        app(EmailService::class)->sendNotification($this->user(), 'PROVIDER_APPROVED', 'Hello.');

        $this->assertCount(0, $requests);
        Mail::assertQueued(\App\Mail\ScholarZimMail::class);
    }

    // ------------------------------------------------------------- helpers --

    /** @param array<int, array<string, mixed>> $requests captured by reference */
    private function fakeHttp(array &$requests): void
    {
        $this->app->instance(HttpClientInterface::class, new MockHttpClient(
            function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

                return new MockResponse(
                    (string) json_encode(['id' => '<a@b>', 'message' => 'Queued. Thank you.']),
                    ['http_code' => 200]
                );
            }
        ));
    }

    private function fakeHttpResponding(int $status, string $body): void
    {
        $this->app->instance(HttpClientInterface::class, new MockHttpClient(
            fn () => new MockResponse($body, ['http_code' => $status])
        ));
    }

    private function user(): User
    {
        $user = new User();
        $user->user_id = 4242;
        $user->email = 'nobody@example.test';
        $user->full_name = 'Nobody Real';

        return $user;
    }
}
