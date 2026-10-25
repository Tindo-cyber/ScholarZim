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
     * Whether listing forms posted by this test get the broadest "who is it open to?" answer filled in.
     *
     * That answer became required at Certificate level and above (ListingScopes::decisionProblems). The many
     * tests written before it are about something else - a deadline, a subject row, a trust tier - and post
     * a form without it. Rather than repeat the same two keys in each of them, a listing form that does not
     * answer the question is sent answering it as broadly as possible: "all programmes", "any institution".
     * Only where the form has not already answered it, and never over a list of programmes, fields or
     * institutions it chose itself. Tests about the rule itself switch this off with withoutOpenToAnswer().
     */
    private bool $fillOpenToAnswer = true;

    protected function withoutOpenToAnswer(): static
    {
        $this->fillOpenToAnswer = false;

        return $this;
    }

    /**
     * Whether listing forms posted by this test get a number of awards.
     *
     * The number of awards became required to publish (StoreOpportunityRequest). The many tests written before it are about
     * something else and post a form without it, so a listing form that does not answer is sent with one award - the same
     * convention as the "open to" answer above. Drafts are not affected (they are saved by their own route and may omit it).
     * Tests about the rule itself switch this off with withoutAwardCountAnswer().
     */
    private bool $fillAwardCount = true;

    protected function withoutAwardCountAnswer(): static
    {
        $this->fillAwardCount = false;

        return $this;
    }

    /** @param  array<string, mixed>  $parameters */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->fillOpenToAnswer && in_array(strtoupper($method), ['POST', 'PUT'], true)
            && preg_match('#/opportunities/(create|preview|\d+|\d+/edit-impact)/?$#', (string) parse_url((string) $uri, PHP_URL_PATH))
            && is_array($parameters)) {
            $chose = static fn (string $key) => ! empty($parameters[$key]);

            if (! array_key_exists('open_to', $parameters) && ! $chose('scope_programmes') && ! $chose('scope_fields') && ! $chose('programme_suggestion')) {
                $parameters['open_to'] = \App\Services\Catalogue\ListingScopes::OPEN_ALL;
            }

            if (! array_key_exists('institutions_open_to', $parameters) && ! $chose('scope_institutions')) {
                $parameters['institutions_open_to'] = \App\Services\Catalogue\ListingScopes::INSTITUTIONS_ANY;
            }
        }

        if ($this->fillAwardCount && in_array(strtoupper($method), ['POST', 'PUT'], true)
            && preg_match('#/opportunities/(create|preview|\d+)/?$#', (string) parse_url((string) $uri, PHP_URL_PATH))
            && is_array($parameters) && ! array_key_exists('award_slots', $parameters)) {
            $parameters['award_slots'] = '1';

            // An edit of a listing that has no count yet would otherwise change it (null -> 1), which is a material edit and
            // not what those tests are about; the listing is given the same count first, so the edit leaves it as it was.
            if (strtoupper($method) === 'PUT' && preg_match('#/opportunities/(\d+)/?$#', (string) parse_url((string) $uri, PHP_URL_PATH), $m)) {
                \App\Models\Opportunity::whereKey((int) $m[1])->whereNull('award_slots')->update(['award_slots' => 1]);
            }
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** For the few tests about the real shipped catalogue (call it after parent::setUp()). */
    protected function useFullCatalogue(): void
    {
        config(['scholarzim.catalogue.path' => null]);
    }

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

        // Every seeded test loads the programme catalogue. The shipped one has over a thousand programmes, so tests
        // use a small copy of the original starter list unless they ask for the real thing.
        config(['scholarzim.catalogue.path' => base_path('tests/Fixtures/catalogue')]);

        // Documents live on a disk, and a document counts only when its file is there. Every test starts on a faked
        // one, so the seeder's demo documents (written to the configured disk) land in it and never in storage/app.
        \Illuminate\Support\Facades\Storage::fake((string) config('filesystems.default', 'local'));

        $this->app->instance(HttpClientInterface::class, new MockHttpClient(
            fn () => new MockResponse(
                (string) json_encode(['id' => '<test@example.test>', 'message' => 'Queued. Thank you.']),
                ['http_code' => 200]
            )
        ));
    }
}
