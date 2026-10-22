<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\Deadlines;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A new deadline must be strictly after today, where today is the date in Harare.
 *
 * The application clock is UTC (config/app.php), two hours behind Harare, so between 22:00 and 24:00 UTC the two disagree
 * about the date; the boundary cases are tried with the clock set there. The browser's `min` is only guidance: every case
 * also goes to the server as a hand-made request.
 */
class DeadlineRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        // Midday UTC on a Thursday: Harare and UTC agree it is 22 October.
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ----------------------------------------------------------------- helpers --

    private function create(mixed $deadline, array $more = [])
    {
        return $this->actingAs($this->provider)->post('/opportunities/create', array_filter([
            'title' => 'Deadline Award',
            'description' => 'A listing used to test the deadline.',
            'education_level' => EducationLevel::FORM_1,
            'funding_type' => 'Full Scholarship',
            'deadline' => $deadline,
        ] + $more, fn ($v) => $v !== null));
    }

    private function listing(array $attributes = []): Opportunity
    {
        return Opportunity::create($attributes + [
            'provider_user_id' => $this->provider->user_id, 'provider_name' => $this->provider->full_name,
            'title' => 'Existing Award ' . uniqid(), 'description' => 'A fixture.', 'education_level' => EducationLevel::FORM_1,
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => '2026-11-30', 'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDays(5),
        ]);
    }

    private function edit(Opportunity $listing, array $more = [])
    {
        return $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, array_filter($more + [
            'title' => $listing->title, 'description' => $listing->description, 'education_level' => EducationLevel::canonical($listing->education_level),
            'funding_type' => $listing->funding_type, 'country' => $listing->country, 'deadline' => $listing->deadline?->toDateString(), 'reason' => 'A change.',
        ], fn ($v) => $v !== null));
    }

    private function created(): int
    {
        return Opportunity::where('title', 'Deadline Award')->count();
    }

    // -------------------------------------------------------------- the rule --

    public function test_yesterday_and_today_are_rejected_and_tomorrow_is_accepted(): void
    {
        $this->create('2026-10-21')->assertSessionHasErrors(['deadline' => 'The deadline must be after today. Choose tomorrow or later.']);
        $this->create('2026-10-22')->assertSessionHasErrors(['deadline' => 'The deadline must be after today. Choose tomorrow or later.']);
        $this->assertSame(0, $this->created());

        $this->create('2026-10-23')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->created());
        $this->assertSame('2026-10-23', Opportunity::where('title', 'Deadline Award')->firstOrFail()->deadline->toDateString());
    }

    public static function malformed(): array
    {
        return [['abc'], ['2026-02-30'], ['22/10/2026'], ['2026-13-01'], ['2026-10-23 10:00'], ['tomorrow'], ['1e3'], [['2026-10-23']], ['0000-00-00']];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_deadline_is_rejected(mixed $deadline): void
    {
        $this->create($deadline)->assertSessionHasErrors('deadline');
        $this->assertSame(0, $this->created());
    }

    public function test_no_deadline_is_a_rolling_intake_as_before(): void
    {
        $this->create(null)->assertSessionHasNoErrors();

        $this->assertNull(Opportunity::where('title', 'Deadline Award')->firstOrFail()->deadline);
    }

    public function test_the_rule_follows_the_date_in_harare_not_in_utc(): void
    {
        // 21:59 UTC is 23:59 on the 22nd in Harare: the 23rd is still tomorrow.
        Carbon::setTestNow(Carbon::parse('2026-10-22 21:59:00', 'UTC'));
        $this->assertSame('2026-10-22', Deadlines::today()->toDateString());
        $this->create('2026-10-22')->assertSessionHasErrors('deadline');
        $this->create('2026-10-23')->assertSessionHasNoErrors();

        Opportunity::where('title', 'Deadline Award')->delete();

        // 22:00 UTC is midnight on the 23rd in Harare: the 23rd is now today, though UTC still says the 22nd.
        Carbon::setTestNow(Carbon::parse('2026-10-22 22:00:00', 'UTC'));
        $this->assertSame('2026-10-23', Deadlines::today()->toDateString());
        $this->create('2026-10-23')->assertSessionHasErrors('deadline');
        $this->create('2026-10-24')->assertSessionHasNoErrors();
    }

    public function test_the_form_offers_tomorrow_as_the_earliest_date(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<input[^>]*min="2026-10-23"[^>]*name="deadline"#', $html);

        Carbon::setTestNow(Carbon::parse('2026-10-22 23:30:00', 'UTC'));
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<input[^>]*min="2026-10-24"[^>]*name="deadline"#', $html, 'half past midnight in Harare, so tomorrow is the 24th');
    }

    // ------------------------------------------------------------------ drafts --

    public function test_a_draft_may_hold_any_date_but_submitting_it_applies_the_rule(): void
    {
        $this->actingAs($this->provider)->post('/opportunities/draft', ['title' => 'Deadline Award', 'deadline' => '2026-10-22'])->assertSessionHasNoErrors();
        $draft = Opportunity::where('title', 'Deadline Award')->firstOrFail();

        $this->assertTrue($draft->isDraft(), 'a draft is unfinished and stores what it is given');

        $this->create('2026-10-22', ['draft_id' => $draft->opportunity_id])->assertSessionHasErrors('deadline');
        $this->assertTrue($draft->fresh()->isDraft(), 'still a draft');

        $this->create('2026-10-23', ['draft_id' => $draft->opportunity_id])->assertSessionHasNoErrors();
        $this->assertFalse($draft->fresh()->isDraft(), 'submitted in place');
        $this->assertSame('2026-10-23', $draft->fresh()->deadline->toDateString());
    }

    // ------------------------------------------------------------------- edits --

    public function test_an_expired_listing_can_be_edited_but_not_given_today_or_a_past_date(): void
    {
        $expired = $this->listing(['deadline' => '2026-10-01', 'status' => OpportunityStatus::CLOSED]);

        $this->edit($expired, ['description' => 'Corrected wording.'])->assertSessionHasNoErrors();
        $this->assertSame('Corrected wording.', $expired->fresh()->description);
        $this->assertSame(OpportunityStatus::CLOSED, $expired->fresh()->status, 'an edit does not reopen it');
        $this->assertSame('2026-10-01', $expired->fresh()->deadline->toDateString(), 'its history is kept');

        foreach (['2026-10-22', '2026-10-21', '2026-09-01'] as $date) {
            $this->edit($expired, ['deadline' => $date])->assertSessionHasErrors('deadline');
        }

        $this->assertSame(OpportunityStatus::CLOSED, $expired->fresh()->status);
        $this->assertSame('2026-10-01', $expired->fresh()->deadline->toDateString());
    }

    public function test_a_live_listing_cannot_be_moved_to_today(): void
    {
        $live = $this->listing(['deadline' => '2026-11-30']);

        $this->edit($live, ['deadline' => '2026-10-22'])->assertSessionHasErrors('deadline');
        $this->assertSame('2026-11-30', $live->fresh()->deadline->toDateString());
    }

    public function test_a_listing_whose_deadline_is_today_can_still_be_edited_without_touching_it(): void
    {
        $today = $this->listing(['deadline' => '2026-10-22']);

        $this->edit($today, ['description' => 'A clearer description.'])->assertSessionHasNoErrors();
        $this->assertSame('A clearer description.', $today->fresh()->description);
    }

    public function test_extending_a_deadline_needs_a_date_after_today(): void
    {
        $live = $this->listing(['deadline' => '2026-10-22']);

        foreach (['2026-10-22', '2026-10-21'] as $date) {
            $this->actingAs($this->provider)->post('/opportunities/' . $live->opportunity_id . '/extend-deadline', ['deadline' => $date, 'reason' => 'More time.'])
                ->assertSessionHasErrors('deadline', null, 'extend-deadline-' . $live->opportunity_id);
        }

        $this->actingAs($this->provider)->post('/opportunities/' . $live->opportunity_id . '/extend-deadline', ['deadline' => '2026-10-23', 'reason' => 'More time.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-10-23', $live->fresh()->deadline->toDateString());
    }

    // ---------------------------------------- an expired listing is not open --

    public function test_a_listing_past_its_deadline_is_not_open_anywhere_even_while_marked_active(): void
    {
        $expired = $this->listing(['title' => 'Expired But Marked Active', 'deadline' => '2026-10-21']);
        $today = $this->listing(['title' => 'Closes Today Exactly', 'deadline' => '2026-10-22']);

        $this->assertFalse(Opportunity::publiclyVisible()->whereKey($expired->opportunity_id)->exists());
        $this->assertTrue(Opportunity::publiclyVisible()->whereKey($today->opportunity_id)->exists(), 'the last day is still open to applicants');

        $search = $this->get('/scholarships?keyword=Expired')->assertOk()->getContent();
        $this->assertStringNotContainsString('Expired But Marked Active', $search);

        $student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->flushSession();
        $this->assertNotSame(200, $this->actingAs($student)->get('/apply/' . $expired->opportunity_id)->getStatusCode(), 'the apply page is not offered');
        $this->assertSame(0, \App\Models\Application::where('opportunity_id', $expired->opportunity_id)->count(), 'nobody can apply to it');
    }

    public function test_history_is_kept_when_a_listing_expires(): void
    {
        $student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $expired = $this->listing(['deadline' => '2026-10-21']);
        $application = \App\Models\Application::forceCreate([
            'opportunity_id' => $expired->opportunity_id, 'user_id' => $student->user_id,
            'application_status' => 'PENDING',
        ]);

        $this->artisan('scholarzim:archive-expired-opportunities')->assertExitCode(0);

        $this->assertSame(OpportunityStatus::CLOSED, $expired->fresh()->status, 'closed by the sweep');
        $this->assertNotNull(\App\Models\Application::find($application->getKey()), 'the application is still there');
        $this->assertNotNull(Opportunity::find($expired->opportunity_id), 'and so is the listing');
    }
}
