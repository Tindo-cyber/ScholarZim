<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RoleNames;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\TestCase;

/**
 * The browser-reachable half of mail:check.
 *
 * It exists because Render's free plan has no shell, so the one deployment
 * where mail actually broke was also the one where the diagnostic could not be
 * run. Two things have to hold: only an administrator may reach it, and the
 * credential must never come back in the response - a fingerprint is the whole
 * point, not a convenience.
 */
class MailDiagnosticsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_SECRET = 'test-secret-must-never-be-printed-9f8e7d6c';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        config([
            'mail.default' => 'mailgun',
            'services.mailgun.domain' => 'mail.example.test',
            'services.mailgun.secret' => self::FAKE_SECRET,
            'services.mailgun.endpoint' => 'api.mailgun.net',
        ]);
    }

    // ------------------------------------------------------------- access --

    public function test_it_is_closed_to_anonymous_visitors(): void
    {
        $this->get('/admin/mail-diagnostics')->assertRedirect('/login');
    }

    public function test_it_is_closed_to_a_non_administrator(): void
    {
        $this->actingAs($this->student())
            ->get('/admin/mail-diagnostics')
            ->assertForbidden();
    }

    public function test_an_administrator_can_read_it(): void
    {
        $this->fakeMailgun(200);

        $this->actingAs($this->admin())
            ->get('/admin/mail-diagnostics')
            ->assertOk()
            ->assertJsonPath('stage_2_mailgun.ok', true)
            ->assertJsonPath('stage_2_mailgun.http_status', 200)
            ->assertJsonPath('stage_1_configuration.mailer', 'mailgun');
    }

    // -------------------------------------------------------- credentials --

    /** The reason the endpoint returns a fingerprint rather than the value. */
    public function test_the_response_never_contains_the_secret(): void
    {
        $this->fakeMailgun(200);

        $body = (string) $this->actingAs($this->admin())
            ->get('/admin/mail-diagnostics')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(self::FAKE_SECRET, $body);
    }

    public function test_the_fingerprint_describes_the_key_without_revealing_it(): void
    {
        $this->fakeMailgun(200);

        $this->actingAs($this->admin())
            ->get('/admin/mail-diagnostics')
            ->assertOk()
            ->assertJsonPath('stage_1_configuration.credential.configured', true)
            ->assertJsonPath('stage_1_configuration.credential.length', strlen(self::FAKE_SECRET))
            ->assertJsonPath(
                'stage_1_configuration.credential.sha256_prefix',
                substr(hash('sha256', self::FAKE_SECRET), 0, 12)
            )
            ->assertJsonPath('stage_1_configuration.credential.needed_cleaning', false);
    }

    /**
     * The invisible fault this whole fingerprint exists to catch: a value
     * pasted into a dashboard with wrapping quotes looks identical to a correct
     * one and fails with a flat 401.
     */
    public function test_a_quoted_secret_is_cleaned_and_flagged(): void
    {
        config(['services.mailgun.secret' => '"' . self::FAKE_SECRET . '"']);
        $this->fakeMailgun(200);

        $this->actingAs($this->admin())
            ->get('/admin/mail-diagnostics')
            ->assertOk()
            ->assertJsonPath('stage_1_configuration.credential.length', strlen(self::FAKE_SECRET))
            ->assertJsonPath('stage_1_configuration.credential.needed_cleaning', true);
    }

    public function test_a_secret_with_a_trailing_newline_is_cleaned(): void
    {
        config(['services.mailgun.secret' => self::FAKE_SECRET . "\n"]);
        $this->fakeMailgun(200);

        $this->actingAs($this->admin())
            ->get('/admin/mail-diagnostics')
            ->assertOk()
            ->assertJsonPath('stage_1_configuration.credential.length', strlen(self::FAKE_SECRET))
            ->assertJsonPath('stage_1_configuration.credential.needed_cleaning', true);
    }

    // ------------------------------------------------------------ outcomes --

    /** A rejected credential must be visible as such, with its status. */
    public function test_a_401_is_reported_with_its_status_and_reason(): void
    {
        $this->fakeMailgun(401, '{"message":"Forbidden"}');

        $this->actingAs($this->admin())
            ->get('/admin/mail-diagnostics')
            ->assertStatus(503)
            ->assertJsonPath('stage_2_mailgun.ok', false)
            ->assertJsonPath('stage_2_mailgun.http_status', 401)
            ->assertJsonPath('stage_2_mailgun.reason', 'unauthorized');
    }

    public function test_missing_configuration_is_reported_without_calling_mailgun(): void
    {
        config(['services.mailgun.secret' => '']);

        $client = new MockHttpClient(function (): MockResponse {
            throw new \LogicException('the diagnostics endpoint contacted Mailgun with no credential');
        });
        $this->app->instance(HttpClientInterface::class, $client);

        $this->actingAs($this->admin())
            ->get('/admin/mail-diagnostics')
            ->assertOk()
            ->assertJsonPath('stage_2_mailgun.checked', false)
            ->assertJsonPath('stage_1_configuration.credential.configured', false);

        $this->assertSame(0, $client->getRequestsCount());
    }

    /** A GET that could email someone is a GET someone eventually triggers by refreshing. */
    public function test_it_never_sends_a_message(): void
    {
        $requests = [];

        $this->app->instance(HttpClientInterface::class, new MockHttpClient(
            function (string $method, string $url) use (&$requests): MockResponse {
                $requests[] = $method . ' ' . $url;

                return new MockResponse('{"domain":{"state":"active"}}', ['http_code' => 200]);
            }
        ));

        $this->actingAs($this->admin())->get('/admin/mail-diagnostics')->assertOk();

        $this->assertCount(1, $requests);
        $this->assertStringStartsWith('GET ', $requests[0]);
        $this->assertStringNotContainsString('/messages', $requests[0]);
    }

    // ------------------------------------------------------------- helpers --

    private function fakeMailgun(int $status, ?string $body = null): void
    {
        $body ??= (string) json_encode([
            'domain' => ['name' => 'mail.example.test', 'state' => 'active'],
            'sending_dns_records' => [
                ['record_type' => 'TXT', 'name' => 'mail.example.test', 'valid' => 'valid'],
            ],
        ]);

        $this->app->instance(HttpClientInterface::class, new MockHttpClient(
            fn () => new MockResponse($body, ['http_code' => $status])
        ));
    }

    private function admin(): User
    {
        return User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->firstOrFail();
    }

    private function student(): User
    {
        return User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }
}
