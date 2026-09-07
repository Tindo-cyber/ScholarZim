<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * No test may reach Mailgun.
     *
     * This matters more than it looks. Mail used to be gated by MAIL_MAILER,
     * which phpunit.xml pins to "array" - so a test that triggered an email
     * without Mail::fake() was harmless, because the array transport swallowed
     * it. Delivery now goes through MailgunApiService and an HTTP client, and
     * that transport setting no longer has any say in it. With
     * QUEUE_CONNECTION=sync the job also runs inline, so any of the dozens of
     * tests that approve a listing or decide an application would post to
     * Mailgun for real - against whichever credentials happen to be in the
     * developer's .env, and quite possibly emailing live addresses from a test
     * run.
     *
     * Binding a MockHttpClient here closes that off for the whole suite rather
     * than relying on every test author to remember. It answers like Mailgun
     * accepting a message, so tests that do not care about mail see the same
     * silent success the array transport used to give them. Tests that do care
     * rebind this with their own responses.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(HttpClientInterface::class, new MockHttpClient(
            fn () => new MockResponse(
                (string) json_encode(['id' => '<test@example.test>', 'message' => 'Queued. Thank you.']),
                ['http_code' => 200]
            )
        ));
    }
}
