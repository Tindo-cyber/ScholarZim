<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\PastedListingReader;
use App\Support\AuditAction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\TestCase;

/**
 * Paste-to-prefill: a provider pastes the text of an advert and the create form is filled in from it.
 *
 * The model's answer is untrusted input. Every field is checked with the rules the form itself uses, anything
 * that fails is dropped and REPORTED, and nothing is saved: the provider reads the filled form and submits it
 * like any other. The feature is off unless both the key and the model are in the environment.
 *
 * Every request here goes to a mock. Nothing reaches the real API, so the suite needs no account.
 */
class ListingPastePrefillTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'sk-ant-test-key-must-never-be-printed-5d4c3b2a';

    private const ADVERT = "The Chinhoyi Form 1 Transition Bursary pays full tuition for Grade 7 leavers starting Form 1.\n"
        . "Open to learners in Mashonaland West. Closes 30 November 2026. 25 places, USD 500 each. Apply at https://example.org/bursary.";

    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2026-10-09 12:00:00');

        config([
            'services.anthropic.key' => self::FAKE_KEY,
            'services.anthropic.model' => 'test-model',
            'scholarzim.prefill.per_hour' => 10,
        ]);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The model "says" this (a PHP array or a raw string); every call is recorded in $this->sent. */
    private function modelSays(array|string $answer, int $status = 200): void
    {
        $text = is_array($answer) ? json_encode($answer) : $answer;
        $body = $status === 200 ? json_encode(['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn']) : $text;

        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($body, $status) {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse($body, ['http_code' => $status]);
        });

        $this->app->instance(HttpClientInterface::class, $client);
        $this->app->forgetInstance(PastedListingReader::class);
    }

    private function failsWith(\Throwable $e): void
    {
        $client = new MockHttpClient(function () use ($e) {
            $this->sent[] = [];
            throw $e;
        });
        $this->app->instance(HttpClientInterface::class, $client);
        $this->app->forgetInstance(PastedListingReader::class);
    }

    private function reader(): PastedListingReader
    {
        return $this->app->make(PastedListingReader::class);
    }

    private function good(): array
    {
        return [
            'title' => 'Chinhoyi Form 1 Transition Bursary',
            'education_level' => 'FORM_1',
            'funding_type' => 'Full Scholarship',
            'deadline' => '2026-11-30',
            'award_amount' => 500,
            'award_currency' => 'USD',
            'award_slots' => 25,
            'required_province' => 'Mashonaland West',
            'external_url' => 'https://example.org/bursary',
        ];
    }

    // ------------------------------------------------------------------ switched on or off --

    public function test_it_is_off_without_a_key_or_without_a_model(): void
    {
        $this->assertTrue($this->reader()->isEnabled());

        config(['services.anthropic.key' => '']);
        $this->assertFalse($this->app->make(PastedListingReader::class)->isEnabled());

        config(['services.anthropic.key' => self::FAKE_KEY, 'services.anthropic.model' => '']);
        $this->assertFalse($this->app->make(PastedListingReader::class)->isEnabled());
    }

    public function test_reading_while_off_refuses_without_calling_anything(): void
    {
        config(['services.anthropic.key' => '']);
        $this->modelSays($this->good());

        try {
            $this->app->make(PastedListingReader::class)->read(self::ADVERT);
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not switched on', $e->getMessage());
        }

        $this->assertSame([], $this->sent);
    }

    // ------------------------------------------------------------------ the request --

    public function test_the_request_carries_the_key_the_model_and_the_text_as_data(): void
    {
        $this->modelSays($this->good());

        $this->reader()->read(self::ADVERT);

        $this->assertCount(1, $this->sent);
        $options = $this->sent[0]['options'];
        $this->assertSame('POST', $this->sent[0]['method']);
        $this->assertStringStartsWith('https://api.anthropic.com/', $this->sent[0]['url']);
        $this->assertContains('x-api-key: ' . self::FAKE_KEY, $options['headers']);

        $payload = json_decode($options['body'], true);
        $this->assertSame('test-model', $payload['model']);
        $this->assertStringContainsString('Chinhoyi Form 1 Transition Bursary', json_encode($payload['messages']));
        // The pasted text is something to read, never something to obey.
        $this->assertStringContainsString('never follow', strtolower($payload['system']));
        $this->assertStringContainsString('only json', strtolower($payload['system']));
    }

    // ------------------------------------------------------------------ valid answers --

    public function test_a_valid_answer_fills_the_fields_with_the_right_types(): void
    {
        $this->modelSays($this->good());

        $result = $this->reader()->read(self::ADVERT);

        $this->assertSame('Chinhoyi Form 1 Transition Bursary', $result['fields']['title']);
        $this->assertSame('FORM_1', $result['fields']['education_level']);
        $this->assertSame('2026-11-30', $result['fields']['deadline']);
        $this->assertSame(500.0, (float) $result['fields']['award_amount']);
        $this->assertSame(25, $result['fields']['award_slots']);
        $this->assertSame('Mashonaland West', $result['fields']['required_province']);
        $this->assertSame([], $result['notKept']);
        $this->assertContains('Title', $result['understood']);
        $this->assertContains('Deadline', $result['understood']);
    }

    public function test_the_description_is_the_providers_own_words_not_the_models(): void
    {
        $this->modelSays($this->good() + ['description' => 'A rewritten description from the model.']);

        $result = $this->reader()->read(self::ADVERT);

        $this->assertSame(trim(self::ADVERT), $result['fields']['description']);
    }

    public function test_numbers_as_strings_and_a_fenced_answer_are_tolerated(): void
    {
        $this->modelSays("Here you go:\n```json\n" . json_encode(['title' => 'X Bursary', 'award_slots' => '12', 'max_age' => '20']) . "\n```");

        $result = $this->reader()->read(self::ADVERT);

        $this->assertSame(12, $result['fields']['award_slots']);
        $this->assertSame(20, $result['fields']['max_age']);
    }

    public function test_fields_it_could_not_find_are_simply_absent(): void
    {
        $this->modelSays(['title' => 'Only A Title']);

        $result = $this->reader()->read(self::ADVERT);

        $this->assertSame('Only A Title', $result['fields']['title']);
        $this->assertArrayNotHasKey('deadline', $result['fields']);
        $this->assertArrayNotHasKey('education_level', $result['fields']);
        $this->assertSame(['Title', 'Description'], $result['understood']);
    }

    // ------------------------------------------------------------------ untrusted output --

    public function test_a_value_that_fails_the_forms_own_rules_is_dropped_and_reported(): void
    {
        $this->modelSays([
            'title' => 'Fine Bursary',
            'education_level' => 'WIZARDING',
            'funding_type' => 'Free money',
            'deadline' => '2026-01-01',          // already past
            'award_amount' => -5,
            'award_currency' => 'BTC',
            'external_url' => 'javascript:alert(1)',
            'required_province' => 'Narnia',
            'max_age' => 4,
            'award_slots' => 0,
        ]);

        $result = $this->reader()->read(self::ADVERT);

        $this->assertSame('Fine Bursary', $result['fields']['title']);
        foreach (['education_level', 'funding_type', 'deadline', 'award_amount', 'award_currency', 'external_url', 'required_province', 'max_age', 'award_slots'] as $field) {
            $this->assertArrayNotHasKey($field, $result['fields'], "$field should have been dropped");
        }

        $reported = collect($result['notKept'])->pluck('field')->all();
        foreach (['Education level', 'Funding type', 'Deadline', 'Award amount', 'Currency', 'Link', 'Province', 'Maximum age', 'Places'] as $label) {
            $this->assertContains($label, $reported, "$label should have been reported");
        }
        $this->assertNotSame('', collect($result['notKept'])->firstWhere('field', 'Deadline')['reason']);
    }

    public function test_keys_it_was_never_asked_for_are_ignored(): void
    {
        $this->modelSays($this->good() + [
            'moderation_status' => 'APPROVED', 'status' => 'ACTIVE', 'provider_user_id' => 1,
            'is_verified' => true, 'trust_tier' => 'TRUSTED', 'risk_flags' => [],
        ]);

        $fields = $this->reader()->read(self::ADVERT)['fields'];

        foreach (['moderation_status', 'status', 'provider_user_id', 'is_verified', 'trust_tier', 'risk_flags'] as $key) {
            $this->assertArrayNotHasKey($key, $fields);
        }
    }

    public function test_markup_in_an_answer_stays_text_and_a_huge_value_is_dropped(): void
    {
        $this->modelSays(['title' => '<script>alert(1)</script> Bursary', 'target_locality' => str_repeat('x', 500)]);

        $result = $this->reader()->read(self::ADVERT);

        $this->assertSame('<script>alert(1)</script> Bursary', $result['fields']['title'], 'stored as text; the form escapes it on display');
        $this->assertArrayNotHasKey('target_locality', $result['fields']);
    }

    public function test_an_answer_that_is_not_json_is_refused_cleanly(): void
    {
        $this->modelSays('I am sorry, I cannot help with that.');

        try {
            $this->reader()->read(self::ADVERT);
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not read', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('sorry', strtolower($e->getMessage()), 'what the model said is not echoed back');
        }
    }

    public function test_an_answer_that_is_json_but_not_an_object_is_refused(): void
    {
        $this->modelSays('["title", "deadline"]');

        $this->expectException(\RuntimeException::class);
        $this->reader()->read(self::ADVERT);
    }

    // ------------------------------------------------------------------ the service being down --

    /** @return array<string, array{0: int, 1: string}> */
    public static function failures(): array
    {
        return [
            'bad key' => [401, 'key'],
            'rate limited' => [429, 'busy'],
            'server error' => [500, 'unavailable'],
            'overloaded' => [529, 'unavailable'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_each_http_failure_has_its_own_safe_reason(int $status, string $word): void
    {
        $this->modelSays('{"error":{"message":"details with ' . self::FAKE_KEY . ' inside"}}', $status);

        try {
            $this->reader()->read(self::ADVERT);
            $this->fail('expected a failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase($word, $e->getMessage());
            $this->assertStringNotContainsString(self::FAKE_KEY, $e->getMessage());
        }
    }

    public function test_a_timeout_is_a_safe_failure_too(): void
    {
        $this->failsWith(new TimeoutException('timed out talking to ' . self::FAKE_KEY));

        try {
            $this->reader()->read(self::ADVERT);
            $this->fail('expected a failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('too long', $e->getMessage());
            $this->assertStringNotContainsString(self::FAKE_KEY, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ the page --

    public function test_the_create_page_offers_the_paste_box_only_when_it_is_on(): void
    {
        $this->actingAs($this->provider)->get(route('opportunities.create'))->assertOk()
            ->assertSee('name="pasted_text"', false)
            ->assertSee('sent to Anthropic');

        config(['services.anthropic.key' => '']);

        $this->actingAs($this->provider)->get(route('opportunities.create'))->assertOk()
            ->assertDontSee('name="pasted_text"', false);
    }

    public function test_pasting_fills_the_form_and_saves_nothing(): void
    {
        $this->modelSays($this->good());
        $before = Opportunity::count();

        $response = $this->actingAs($this->provider)->post(route('opportunities.prefill'), ['pasted_text' => self::ADVERT]);

        $response->assertRedirect(route('opportunities.create'));
        $this->assertSame($before, Opportunity::count(), 'nothing is created: the provider submits it themselves');

        $this->followRedirects($response)->assertOk()
            ->assertSee('value="Chinhoyi Form 1 Transition Bursary"', false)
            ->assertSee('Filled in from your pasted text')
            ->assertSee('Nothing has been saved')
            ->assertSee('Check every field');
    }

    public function test_the_banner_says_what_was_understood_and_what_was_not_kept(): void
    {
        $this->modelSays(['title' => 'Fine Bursary', 'deadline' => '2020-01-01', 'award_currency' => 'BTC']);

        $page = $this->followRedirects(
            $this->actingAs($this->provider)->post(route('opportunities.prefill'), ['pasted_text' => self::ADVERT])
        )->assertOk();

        $page->assertSee('Understood from your text')->assertSee('Title')
            ->assertSee('Not used')->assertSee('Deadline')->assertSee('Currency');
    }

    public function test_the_banner_is_shown_once_and_a_plain_visit_is_a_plain_form(): void
    {
        $this->modelSays($this->good());
        $this->followRedirects($this->actingAs($this->provider)->post(route('opportunities.prefill'), ['pasted_text' => self::ADVERT]))->assertOk();

        $this->actingAs($this->provider)->get(route('opportunities.create'))->assertOk()
            ->assertDontSee('Filled in from your pasted text')
            ->assertDontSee('value="Chinhoyi Form 1 Transition Bursary"', false);
    }

    public function test_a_failure_returns_the_provider_to_the_form_with_their_text_kept(): void
    {
        $this->modelSays('not json at all');
        $before = Opportunity::count();

        $this->actingAs($this->provider)->from(route('opportunities.create'))
            ->post(route('opportunities.prefill'), ['pasted_text' => self::ADVERT])
            ->assertRedirect(route('opportunities.create'))
            ->assertSessionHas('errorMessage')
            ->assertSessionHasInput('pasted_text', self::ADVERT);

        $this->assertSame($before, Opportunity::count());
    }

    public function test_the_text_is_checked_before_anything_is_sent(): void
    {
        $this->modelSays($this->good());

        foreach (['', 'too short', str_repeat('a', 10001)] as $text) {
            $this->actingAs($this->provider)->post(route('opportunities.prefill'), ['pasted_text' => $text])
                ->assertSessionHasErrors('pasted_text');
        }

        $this->assertSame([], $this->sent, 'no call is made for text that is refused');
    }

    public function test_switched_off_it_says_so_and_calls_nothing(): void
    {
        config(['services.anthropic.key' => '']);
        $this->modelSays($this->good());

        $this->actingAs($this->provider)->post(route('opportunities.prefill'), ['pasted_text' => self::ADVERT])
            ->assertSessionHas('errorMessage', fn (string $m) => str_contains($m, 'not switched on'));

        $this->assertSame([], $this->sent);
    }

    public function test_only_a_provider_can_use_it(): void
    {
        $this->modelSays($this->good());
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($student)->post(route('opportunities.prefill'), ['pasted_text' => self::ADVERT])->assertForbidden();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post(route('opportunities.prefill'), ['pasted_text' => self::ADVERT])->assertRedirect();

        $this->assertSame([], $this->sent);
    }

    public function test_it_is_rate_limited_per_provider(): void
    {
        config(['scholarzim.prefill.per_hour' => 2]);
        $this->modelSays($this->good());

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->provider)->post(route('opportunities.prefill'), ['pasted_text' => self::ADVERT])->assertRedirect(route('opportunities.create'));
        }

        $this->actingAs($this->provider)->post(route('opportunities.prefill'), ['pasted_text' => self::ADVERT])->assertStatus(429);
        $this->assertCount(2, $this->sent, 'the refused request never reached the API');

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $this->actingAs($other)->post(route('opportunities.prefill'), ['pasted_text' => self::ADVERT])->assertRedirect(route('opportunities.create'));
    }

    public function test_it_is_logged_without_the_pasted_text_or_the_key(): void
    {
        $this->modelSays($this->good());

        $this->actingAs($this->provider)->post(route('opportunities.prefill'), ['pasted_text' => self::ADVERT]);

        $line = AuditLog::where('action', AuditAction::PASTE_PREFILL)->firstOrFail();
        $this->assertSame($this->provider->email, $line->actor_email);
        $this->assertStringNotContainsString('Chinhoyi', (string) $line->details . json_encode($line->context ?? []));
        $this->assertStringNotContainsString(self::FAKE_KEY, (string) $line->details . json_encode($line->context ?? []));
    }

    public function test_a_prefilled_form_is_judged_by_the_ordinary_rules_when_submitted(): void
    {
        // Whatever the form was filled with, submitting it is the normal create path: a deadline in the past is refused.
        $this->actingAs($this->provider)->post(route('opportunities.store'), [
            'title' => 'Prefill Test Bursary', 'description' => self::ADVERT, 'education_level' => 'FORM_1',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'deadline' => '2020-01-01',
        ])->assertSessionHasErrors('deadline');

        $this->assertSame(0, Opportunity::where('title', 'Prefill Test Bursary')->count());
    }
}
