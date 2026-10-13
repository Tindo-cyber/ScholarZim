<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\User;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ReportReason;
use App\Support\ReportStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "My reports": the student's own place to see the reports they have made to the administrator and what
 * became of each. Filing a report still happens on a scholarship's page; this page lists them.
 */
class MyReportsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $other;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2026-10-13 12:00:00');

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->other = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        OpportunityReport::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function listing(string $title, array $more = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Reported Trust', 'title' => $title,
            'description' => 'x', 'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDays(20), 'created_at' => now(),
        ], $more));
    }

    private function report(User $by, Opportunity $listing, string $status = ReportStatus::PENDING, array $more = []): OpportunityReport
    {
        return OpportunityReport::create(array_merge([
            'opportunity_id' => $listing->opportunity_id, 'user_id' => $by->user_id, 'reason' => ReportReason::ASKS_FOR_MONEY,
            'details' => null, 'status' => $status, 'created_at' => now()->subDays(2),
        ], $more));
    }

    private function page(?User $as = null): string
    {
        return $this->actingAs($as ?? $this->student)->get(route('applicant.reports'))->assertOk()->getContent();
    }

    // -------------------------------------------------------------- who can open it --

    public function test_it_is_for_signed_in_students_only(): void
    {
        $this->get(route('applicant.reports'))->assertRedirect(route('login'));

        $this->actingAs($this->provider)->get(route('applicant.reports'))->assertForbidden();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $admin = User::where('email', config('scholarzim.admin.email'))->firstOrFail();
        $this->actingAs($admin)->get(route('applicant.reports'))->assertForbidden();
    }

    public function test_the_students_menu_has_a_my_reports_entry(): void
    {
        $this->actingAs($this->student)->get(route('applicant.dashboard'))->assertOk()
            ->assertSee('My reports')
            ->assertSee(route('applicant.reports'), false);
    }

    // -------------------------------------------------------------- what it shows --

    public function test_it_lists_my_reports_with_the_reason_details_date_and_a_link(): void
    {
        $listing = $this->listing('Pay To Apply Award');
        $this->report($this->student, $listing, ReportStatus::PENDING, ['details' => 'They want a USD 20 fee.']);

        $html = $this->page();

        $this->assertStringContainsString('Pay To Apply Award', $html);
        $this->assertStringContainsString(ReportReason::label(ReportReason::ASKS_FOR_MONEY), $html);
        $this->assertStringContainsString('They want a USD 20 fee.', $html);
        $this->assertStringContainsString(now()->subDays(2)->format('d M Y'), $html);
        $this->assertStringContainsString(route('scholarships.show', $listing->opportunity_id), $html);
    }

    public function test_each_outcome_is_said_in_plain_words(): void
    {
        $this->report($this->student, $this->listing('Waiting Award'), ReportStatus::PENDING);
        $this->report($this->student, $this->listing('Agreed Award'), ReportStatus::UPHELD);
        $this->report($this->student, $this->listing('Declined Award'), ReportStatus::DISMISSED);

        $html = $this->page();

        $this->assertStringContainsString('Waiting for an administrator', $html);
        $this->assertStringContainsString('The administrator agreed', $html);
        $this->assertStringContainsString('No action was needed', $html);
    }

    public function test_the_administrators_private_reason_is_never_shown(): void
    {
        $this->report($this->student, $this->listing('Private Award'), ReportStatus::UPHELD, [
            'reviewed_by' => 'admin@scholarzim.co.zw', 'reviewed_at' => now(),
        ]);

        $html = $this->page();

        $this->assertStringNotContainsString('admin@scholarzim.co.zw', $html);
    }

    public function test_newest_first(): void
    {
        $this->report($this->student, $this->listing('Older Award'), ReportStatus::PENDING, ['created_at' => now()->subDays(9)]);
        $this->report($this->student, $this->listing('Newer Award'), ReportStatus::PENDING, ['created_at' => now()->subDay()]);

        $html = $this->page();

        $this->assertLessThan(strpos($html, 'Older Award'), strpos($html, 'Newer Award'));
    }

    public function test_only_my_own_reports_are_shown(): void
    {
        $this->report($this->student, $this->listing('Mine Award'));
        $this->report($this->other, $this->listing('Theirs Award'), ReportStatus::PENDING, ['details' => 'Somebody elses words.']);

        $html = $this->page();

        $this->assertStringContainsString('Mine Award', $html);
        $this->assertStringNotContainsString('Theirs Award', $html);
        $this->assertStringNotContainsString('Somebody elses words.', $html);
    }

    public function test_a_listing_that_is_no_longer_public_is_named_but_not_linked(): void
    {
        $down = $this->listing('Taken Down Award', ['moderation_status' => OpportunityModerationStatus::PENDING]);
        $this->report($this->student, $down, ReportStatus::UPHELD);

        $html = $this->page();

        $this->assertStringContainsString('Taken Down Award', $html);
        $this->assertStringNotContainsString(route('scholarships.show', $down->opportunity_id), $html, 'a link that would 404 is not offered');
    }

    // -------------------------------------------------------------- nothing yet --

    public function test_with_no_reports_it_explains_how_to_make_one(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('You have not reported anything', $html);
        $this->assertStringContainsString('Report this listing', $html, 'tells them where the button is');
        $this->assertStringContainsString(route('opportunities.index'), $html);
    }

    public function test_the_page_always_says_where_a_report_is_made(): void
    {
        $this->report($this->student, $this->listing('Some Award'));

        $this->assertStringContainsString('Report this listing', $this->page());
    }

    public function test_a_long_list_is_paged(): void
    {
        for ($i = 1; $i <= 17; $i++) {
            $this->report($this->student, $this->listing("Bulk Award $i"), ReportStatus::PENDING, ['created_at' => now()->subMinutes($i)]);
        }

        $html = $this->page();

        $this->assertStringContainsString('Bulk Award 1', $html, 'the newest is on page 1');
        $this->assertStringNotContainsString('Bulk Award 17', $html, 'the 17th is on page 2');

        $this->actingAs($this->student)->get(route('applicant.reports', ['page' => 2]))->assertOk()->assertSee('Bulk Award 17');
    }
}
