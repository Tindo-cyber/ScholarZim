<?php

namespace Tests\Feature;

use App\Services\MailgunApiService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\TestCase;

/**
 * The single point at which ScholarZim talks to Mailgun.
 *
 * Two properties are load-bearing and everything else follows from them: the
 * credential must never escape into a return value, an error or a log, and a
 * status code must be turned into a distinct, actionable reason rather than the
 * undifferentiated "Unable to send an email" that hid a two-day outage.
 *
 * Every request here is mocked. Nothing reaches Mailgun, so the suite neither
 * needs an account nor breaks when a key is rotated.
 */
class MailgunApiServiceTest extends TestCase
{
    private const FAKE_SECRET = 'test-secret-must-never-be-printed-9f8e7d6c';

    private const FAKE_DOMAIN = 'mail.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.from.address' => 'noreply@scholarzim.co.zw',
            'mail.from.name' => 'ScholarZim',
            'services.mailgun.domain' => self::FAKE_DOMAIN,
            'services.mailgun.secret' => self::FAKE_SECRET,
            'services.mailgun.endpoint' => 'api.mailgun.net',
        ]);
    }

    // -------------------------------------------------------- URL building --

    public function test_it_builds_the_messages_url_from_the_configured_domain(): void
    {
        $this->assertSame(
            'https://api.mailgun.net/v3/mail.example.test/messages',
            $this->service()->messagesUrl()
        );
    }

    public function test_it_builds_the_read_only_domain_url(): void
    {
        $this->assertSame(
            'https://api.mailgun.net/v3/domains/mail.example.test',
            $this->service()->domainUrl()
        );
    }

    /** An EU-region domain must be reachable by changing one variable. */
    public function test_the_endpoint_is_configurable_for_the_eu_region(): void
    {
        config(['services.mailgun.endpoint' => 'api.eu.mailgun.net']);

        $this->assertStringStartsWith('https://api.eu.mailgun.net/v3/', $this->service()->messagesUrl());
    }

    /**
     * config/services.php stores a bare host, but a hand-set MAILGUN_ENDPOINT
     * very often arrives with the scheme attached. Accepting both is the
     * difference between working and building "https://https://...".
     */
    public function test_an_endpoint_with_a_scheme_does_not_produce_a_doubled_url(): void
    {
        config(['services.mailgun.endpoint' => 'https://api.mailgun.net/']);

        $this->assertSame(
            'https://api.mailgun.net/v3/mail.example.test/messages',
            $this->service()->messagesUrl()
        );
    }

    public function test_an_empty_endpoint_falls_back_to_the_us_region(): void
    {
        config(['services.mailgun.endpoint' => '']);

        $this->assertStringStartsWith('https://api.mailgun.net/', $this->service()->messagesUrl());
    }

    // ---------------------------------------------------- authentication --

    /**
     * Basic auth with the literal username "api" is how both Mailgun private
     * and sending keys authenticate. The assertion checks the option that was
     * passed rather than the header, because that is what the transport acts on.
     */
    public function test_it_authenticates_with_the_configured_secret(): void
    {
        $seen = [];

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return $this->acceptedResponse();
        });

        $this->service($client)->send('someone@example.test', 'Someone', 'Subject', '<p>Body</p>');

        $this->assertSame('POST', $seen['method']);
        $this->assertSame('https://api.mailgun.net/v3/mail.example.test/messages', $seen['url']);
        $this->assertContains(
            'Authorization: Basic ' . base64_encode('api:' . self::FAKE_SECRET),
            $seen['options']['headers']
        );
    }

    public function test_configuration_is_incomplete_without_a_secret(): void
    {
        config(['services.mailgun.secret' => '']);

        $this->assertFalse($this->service()->isConfigured());
    }

    public function test_configuration_is_incomplete_without_a_domain(): void
    {
        config(['services.mailgun.domain' => '']);

        $this->assertFalse($this->service()->isConfigured());
    }

    /** Incomplete configuration must fail locally, never as a network error. */
    public function test_it_does_not_call_mailgun_when_configuration_is_incomplete(): void
    {
        config(['services.mailgun.secret' => '']);

        $client = new MockHttpClient(function (): MockResponse {
            throw new \LogicException('MailgunApiService contacted Mailgun with no credential.');
        });

        $result = $this->service($client)->send('someone@example.test', null, 'Subject', '<p>Body</p>');

        $this->assertFalse($result->success);
        $this->assertSame('not_configured', $result->reason);
        $this->assertNull($result->status);
        $this->assertSame(0, $client->getRequestsCount());
    }

    // ------------------------------------------------------ message fields --

    public function test_it_submits_the_expected_fields(): void
    {
        $body = null;

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body) {
            $body = $options['body'];

            return $this->acceptedResponse();
        });

        $this->service($client)->send(
            'someone@example.test',
            'Someone Real',
            'A subject',
            '<p>Rendered HTML</p>',
            'Rendered text'
        );

        parse_str((string) $body, $fields);

        $this->assertSame('"ScholarZim" <noreply@scholarzim.co.zw>', $fields['from']);
        $this->assertSame('"Someone Real" <someone@example.test>', $fields['to']);
        $this->assertSame('A subject', $fields['subject']);
        $this->assertSame('<p>Rendered HTML</p>', $fields['html']);
        $this->assertSame('Rendered text', $fields['text']);
    }

    public function test_the_text_part_is_omitted_when_not_supplied(): void
    {
        $body = null;

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body) {
            $body = $options['body'];

            return $this->acceptedResponse();
        });

        $this->service($client)->send('someone@example.test', null, 'Subject', '<p>Body</p>');

        parse_str((string) $body, $fields);

        $this->assertArrayNotHasKey('text', $fields);
        $this->assertSame('someone@example.test', $fields['to']);
    }

    // ------------------------------------------------------ status mapping --

    public function test_a_200_is_accepted_and_carries_the_message_id(): void
    {
        $result = $this->service(new MockHttpClient($this->acceptedResponse()))
            ->send('someone@example.test', null, 'Subject', '<p>Body</p>');

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->status);
        $this->assertSame('20260907.abc@mail.example.test', $result->messageId);
        $this->assertNull($result->error);
    }

    public function test_a_202_is_also_treated_as_accepted(): void
    {
        $result = $this->send(202, '{"id":"<x@y>","message":"Queued. Thank you."}');

        $this->assertTrue($result->success);
        $this->assertSame(202, $result->status);
    }

    #[DataProvider('failureStatuses')]
    public function test_it_maps_each_failure_status_to_a_distinct_reason(int $status, string $reason): void
    {
        $result = $this->send($status, '{"message":"Mailgun said something"}');

        $this->assertFalse($result->success);
        $this->assertSame($status, $result->status);
        $this->assertSame($reason, $result->reason);
        $this->assertNotNull($result->error);
    }

    public static function failureStatuses(): array
    {
        return [
            '401 unauthorized' => [401, 'unauthorized'],
            '403 forbidden' => [403, 'forbidden'],
            '404 wrong domain' => [404, 'not_found'],
            '400 bad request' => [400, 'invalid_request'],
            '422 unprocessable' => [422, 'invalid_request'],
            '429 rate limited' => [429, 'rate_limited'],
            '500 server error' => [500, 'server_error'],
            '502 server error' => [502, 'server_error'],
        ];
    }

    public function test_an_unexpected_status_is_reported_rather_than_guessed(): void
    {
        $result = $this->send(418, 'I am a teapot');

        $this->assertFalse($result->success);
        $this->assertSame('unexpected_status', $result->reason);
    }

    public function test_a_timeout_is_distinguished_from_a_connection_failure(): void
    {
        $result = $this->service(new MockHttpClient(function (): MockResponse {
            throw new TimeoutException('Idle timeout reached');
        }))->send('someone@example.test', null, 'Subject', '<p>Body</p>');

        $this->assertFalse($result->success);
        $this->assertSame('timeout', $result->reason);
        $this->assertNull($result->status);
    }

    public function test_a_connection_failure_is_reported_with_its_transport_message(): void
    {
        $result = $this->service(new MockHttpClient(function (): MockResponse {
            throw new TransportException('Could not resolve host: api.mailgun.net');
        }))->send('someone@example.test', null, 'Subject', '<p>Body</p>');

        $this->assertFalse($result->success);
        $this->assertSame('connection_failed', $result->reason);
        $this->assertStringContainsString('Could not resolve host', (string) $result->error);
    }

    // ------------------------------------------------------ read-only check --

    public function test_fetch_domain_uses_a_get_and_returns_the_payload(): void
    {
        $seen = [];

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = ['method' => $method, 'url' => $url, 'hasBody' => isset($options['body'])];

            return new MockResponse(
                (string) json_encode(['domain' => ['state' => 'active'], 'sending_dns_records' => []]),
                ['http_code' => 200]
            );
        });

        $result = $this->service($client)->fetchDomain();

        $this->assertSame('GET', $seen['method']);
        $this->assertFalse($seen['hasBody'], 'a read-only check must not carry a request body');
        $this->assertTrue($result->success);
        $this->assertSame('active', $result->payload['domain']['state']);
    }

    /**
     * The payload rides on the result rather than being cached on the service.
     * A shared cache meant one send quietly overwrote the domain details a
     * diagnostic was about to read.
     */
    public function test_a_send_does_not_overwrite_a_previous_domain_payload(): void
    {
        $client = new MockHttpClient([
            new MockResponse((string) json_encode(['domain' => ['state' => 'active']]), ['http_code' => 200]),
            $this->acceptedResponse(),
        ]);

        $service = $this->service($client);

        $domain = $service->fetchDomain();
        $service->send('someone@example.test', null, 'Subject', '<p>Body</p>');

        $this->assertSame('active', $domain->payload['domain']['state']);
    }

    // ------------------------------------------------------ credential safety --

    #[DataProvider('everyFailurePath')]
    public function test_the_secret_never_appears_in_a_result(callable $arrange): void
    {
        $result = $arrange($this);

        $serialised = json_encode([
            'error' => $result->error,
            'reason' => $result->reason,
            'summary' => $result->summary(),
        ]);

        $this->assertStringNotContainsString(self::FAKE_SECRET, (string) $serialised);
    }

    public static function everyFailurePath(): array
    {
        return [
            '401' => [fn (self $t) => $t->send(401, '{"message":"Forbidden"}')],
            '404' => [fn (self $t) => $t->send(404, '{"message":"Domain not found"}')],
            '500' => [fn (self $t) => $t->send(500, 'server exploded')],
            'transport error echoing the key' => [
                fn (self $t) => $t->service(new MockHttpClient(function (): MockResponse {
                    // A transport that echoes the credential back is exactly what
                    // the redaction exists for. Symfony does not do this, but a
                    // proxy in front of it might.
                    throw new TransportException('auth failed for api:' . self::FAKE_SECRET);
                }))->send('someone@example.test', null, 'Subject', '<p>Body</p>'),
            ],
        ];
    }

    public function test_a_transport_message_containing_the_key_is_redacted(): void
    {
        $result = $this->service(new MockHttpClient(function (): MockResponse {
            throw new TransportException('auth failed for api:' . self::FAKE_SECRET);
        }))->send('someone@example.test', null, 'Subject', '<p>Body</p>');

        $this->assertStringNotContainsString(self::FAKE_SECRET, (string) $result->error);
        $this->assertStringContainsString('[redacted]', (string) $result->error);
    }

    // ------------------------------------------------------------- helpers --

    private function service(?HttpClientInterface $client = null): MailgunApiService
    {
        return new MailgunApiService($client ?? new MockHttpClient($this->acceptedResponse()));
    }

    private function send(int $status, string $body): \App\Support\MailgunResult
    {
        return $this->service(new MockHttpClient(new MockResponse($body, ['http_code' => $status])))
            ->send('someone@example.test', null, 'Subject', '<p>Body</p>');
    }

    private function acceptedResponse(): MockResponse
    {
        return new MockResponse(
            (string) json_encode([
                'id' => '<20260907.abc@mail.example.test>',
                'message' => 'Queued. Thank you.',
            ]),
            ['http_code' => 200]
        );
    }
}
