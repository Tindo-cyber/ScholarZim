<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The application tracker: where an applicant sees, at a glance, how far each application has got.
 *
 * It is the "My applications" page under its proper name - a panel of one progress rail per application
 * (Applied, Under review, Decision) with the date each step was reached - and a shorter copy of it on the
 * dashboard. The rails come from ApplicationStatus::timeline(), the same source the detail page uses, so the
 * two can never disagree.
 */
class ApplicationTrackerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2026-10-09 12:00:00');

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        Application::where('user_id', $this->student->user_id)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function listing(string $title): Opportunity
    {
        return Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Tracker Trust', 'title' => $title,
            'description' => 'No stated requirements.', 'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDays(30), 'created_at' => now(),
        ]);
    }

    private function application(string $title, string $status, array $more = []): Application
    {
        return Application::create([
            'user_id' => $this->student->user_id, 'opportunity_id' => $this->listing($title)->opportunity_id,
            'application_status' => $status, 'personal_statement' => str_repeat('x', 120),
            'submitted_at' => now()->subDays(10),
        ] + $more);
    }

    private function tracker(?string $url = null): string
    {
        return $this->actingAs($this->student)->get($url ?? route('applications.mine'))->assertOk()->getContent();
    }

    // ------------------------------------------------------------- the stages --

    public function test_each_status_has_its_own_stages_and_dates(): void
    {
        $viewed = now()->subDays(8);
        $decided = now()->subDays(5);

        $pending = $this->application('Pending Award', ApplicationStatus::PENDING);
        $reviewing = $this->application('Reviewing Award', ApplicationStatus::PENDING, ['viewed_by_provider_at' => $viewed]);
        $accepted = $this->application('Accepted Award', ApplicationStatus::ACCEPTED, ['viewed_by_provider_at' => $viewed, 'decided_at' => $decided]);
        $rejected = $this->application('Rejected Award', ApplicationStatus::REJECTED, ['viewed_by_provider_at' => $viewed, 'decided_at' => $decided]);
        $withdrawn = $this->application('Withdrawn Award', ApplicationStatus::WITHDRAWN, ['withdrawn_at' => $decided]);

        $labels = fn (Application $a) => array_column($a->trackerStages(), 'label');
        $done = fn (Application $a) => array_column($a->trackerStages(), 'done');
        $dates = fn (Application $a) => array_column($a->trackerStages(), 'date');

        $this->assertSame(['Applied', 'Under review', 'Decision'], $labels($pending));
        $this->assertSame([true, false, false], $done($pending));
        $this->assertSame([true, true, false], $done($reviewing));
        $this->assertSame(['Applied', 'Reviewed', 'Accepted'], $labels($accepted));
        $this->assertSame([true, true, true], $done($accepted));
        $this->assertSame(['Applied', 'Reviewed', 'Not successful'], $labels($rejected));
        $this->assertSame(['Applied', 'Withdrawn'], $labels($withdrawn));

        $this->assertSame([now()->subDays(10)->format('d M Y'), null, null], $dates($pending));
        $this->assertSame([now()->subDays(10)->format('d M Y'), $viewed->format('d M Y'), null], $dates($reviewing));
        $this->assertSame([now()->subDays(10)->format('d M Y'), $viewed->format('d M Y'), $decided->format('d M Y')], $dates($accepted));
        $this->assertSame([now()->subDays(10)->format('d M Y'), $decided->format('d M Y')], $dates($withdrawn));
    }

    public function test_it_uses_the_same_rail_as_the_detail_page(): void
    {
        $application = $this->application('Same Rail', ApplicationStatus::PENDING, ['viewed_by_provider_at' => now()->subDay()]);

        $expected = ApplicationStatus::timeline($application->application_status, $application->viewed_by_provider_at);

        $this->assertSame(array_column($expected, 'label'), array_column($application->trackerStages(), 'label'));
        $this->assertSame(array_column($expected, 'done'), array_column($application->trackerStages(), 'done'));
    }

    // ------------------------------------------------------------- the page --

    public function test_the_page_is_the_application_tracker_with_a_rail_per_application(): void
    {
        $this->application('First Award', ApplicationStatus::PENDING);
        $this->application('Second Award', ApplicationStatus::ACCEPTED, ['viewed_by_provider_at' => now()->subDays(8), 'decided_at' => now()->subDays(5)]);

        $html = $this->tracker();

        $this->assertStringContainsString('Application tracker', $html);
        $this->assertStringContainsString('First Award', $html);
        $this->assertStringContainsString('Second Award', $html);
        $this->assertSame(2, substr_count($html, 'data-sz-tracker-card'), 'one card, one rail, per application');
        $this->assertStringContainsString('Under review', $html);
        $this->assertStringContainsString('Accepted', $html);
    }

    public function test_the_dates_are_on_the_rail(): void
    {
        $this->application('Dated Award', ApplicationStatus::ACCEPTED, ['viewed_by_provider_at' => now()->subDays(8), 'decided_at' => now()->subDays(5)]);

        $html = $this->tracker();

        foreach ([10, 8, 5] as $days) {
            $this->assertStringContainsString(now()->subDays($days)->format('d M Y'), $html);
        }
    }

    public function test_the_rail_is_labelled_for_screen_readers(): void
    {
        $this->application('Readable Award', ApplicationStatus::PENDING);

        $html = $this->tracker();

        $this->assertStringContainsString('aria-label="Progress of your application to Readable Award"', $html);
        $this->assertStringContainsString('Step reached', $html);
        $this->assertStringContainsString('Not reached yet', $html);
    }

    public function test_a_decision_reason_is_shown_with_the_rail(): void
    {
        $this->application('Reasoned Award', ApplicationStatus::REJECTED, [
            'viewed_by_provider_at' => now()->subDays(8), 'decided_at' => now()->subDays(5), 'decision_reason' => 'Places were filled.',
        ]);

        $this->assertStringContainsString('Places were filled.', $this->tracker());
    }

    public function test_it_links_to_the_application_and_the_listing(): void
    {
        $application = $this->application('Linked Award', ApplicationStatus::PENDING);

        $html = $this->tracker();

        $this->assertStringContainsString(route('applications.confirmation', $application->application_id), $html);
    }

    public function test_only_my_own_applications_are_shown(): void
    {
        $this->application('Mine', ApplicationStatus::PENDING);
        $other = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        Application::create([
            'user_id' => $other->user_id, 'opportunity_id' => $this->listing('Someone Elses')->opportunity_id,
            'application_status' => ApplicationStatus::PENDING, 'personal_statement' => str_repeat('x', 120), 'submitted_at' => now(),
        ]);

        $html = $this->tracker();

        $this->assertStringContainsString('Mine', $html);
        $this->assertStringNotContainsString('Someone Elses', $html);
    }

    public function test_the_status_filter_narrows_the_tracker_too(): void
    {
        $this->application('Waiting Award', ApplicationStatus::PENDING);
        $this->application('Won Award', ApplicationStatus::ACCEPTED, ['decided_at' => now()->subDay()]);

        $html = $this->tracker(route('applications.mine', ['status' => ApplicationStatus::ACCEPTED]));

        $this->assertStringContainsString('Won Award', $html);
        $this->assertStringNotContainsString('Waiting Award', $html);
        $this->assertSame(1, substr_count($html, 'data-sz-tracker-card'));
    }

    public function test_with_no_applications_it_says_how_to_start_and_shows_no_rails(): void
    {
        $html = $this->tracker();

        $this->assertStringNotContainsString('data-sz-tracker-card', $html);
        $this->assertStringContainsString('Browse scholarships', $html);
    }

    // ------------------------------------------------------------- the dashboard --

    public function test_the_dashboard_shows_the_tracker_for_the_latest_three_and_links_to_the_rest(): void
    {
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $i => $name) {
            $this->application($name . ' Award', ApplicationStatus::PENDING, ['submitted_at' => now()->subDays(10 - $i)]);
        }

        $html = $this->actingAs($this->student)->get(route('applicant.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Application tracker', $html);
        $this->assertSame(3, substr_count($html, 'data-sz-tracker-card'));
        $this->assertStringContainsString('Delta Award', $html, 'the newest is in');
        $this->assertStringNotContainsString('Alpha Award', $html, 'the oldest is not');
        $this->assertStringContainsString(route('applications.mine'), $html);
    }

    public function test_the_dashboard_with_no_applications_has_no_rails(): void
    {
        $html = $this->actingAs($this->student)->get(route('applicant.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-sz-tracker-card', $html);
    }

    // ------------------------------------------------------------- the menu --

    public function test_the_applicants_menu_calls_it_the_application_tracker(): void
    {
        $this->actingAs($this->student)->get(route('applicant.dashboard'))->assertOk()
            ->assertSee('Application tracker')
            ->assertDontSee('>My applications<', false);
    }

    public function test_the_providers_menu_is_unchanged(): void
    {
        $this->actingAs($this->provider)->get(route('provider.dashboard'))->assertOk()
            ->assertDontSee('Application tracker');
    }
}
