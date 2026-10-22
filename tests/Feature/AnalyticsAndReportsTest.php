<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\SavedScholarship;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ExcelReportService;
use App\Services\ProviderAnalyticsService;
use App\Support\ApplicationStatus;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * The numbers on the analytics pages and in the exports, checked against data this test creates and counts for itself:
 * every expected figure is a difference from a baseline taken first, or a count made directly on the tables, never read
 * back from the service under test.
 */
class AnalyticsAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private User $otherProvider;

    private User $student;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00', 'UTC'));

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->otherProvider = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ----------------------------------------------------------------- helpers --

    private function listing(User $owner, array $attributes = []): Opportunity
    {
        return Opportunity::create($attributes + [
            'provider_user_id' => $owner->user_id, 'provider_name' => $owner->full_name, 'title' => 'Analytics Award ' . uniqid(),
            'description' => 'A fixture.', 'education_level' => EducationLevel::FORM_1, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'deadline' => '2026-12-31',
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => now()->subDays(5),
        ]);
    }

    /** The next seeded applicant who has not applied to this listing: one person applies to a listing once. */
    private function application(Opportunity $listing, string $status, ?string $submitted = null): Application
    {
        $applicant = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))
            ->whereNotIn('user_id', Application::where('opportunity_id', $listing->opportunity_id)->pluck('user_id'))
            ->orderBy('user_id')->firstOrFail();

        return Application::forceCreate([
            'opportunity_id' => $listing->opportunity_id, 'user_id' => $applicant->user_id,
            'application_status' => $status, 'submitted_at' => $submitted ?? now()->subDay(),
        ]);
    }

    private function sheet(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'szx');
        file_put_contents($path, $bytes);

        try {
            return IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false);
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------- the provider's numbers --

    public function test_a_providers_totals_count_their_own_listings_applications_saves_and_views_only(): void
    {
        $before = app(ProviderAnalyticsService::class)->overview($this->provider);

        $mine = $this->listing($this->provider, ['view_count' => 40]);
        $draft = $this->listing($this->provider, ['moderation_status' => OpportunityModerationStatus::DRAFT, 'view_count' => 1000]);
        $theirs = $this->listing($this->otherProvider, ['view_count' => 7]);

        $this->application($mine, ApplicationStatus::PENDING);
        $this->application($mine, ApplicationStatus::ACCEPTED);
        $this->application($mine, ApplicationStatus::REJECTED);
        $this->application($mine, ApplicationStatus::WITHDRAWN);
        $this->application($theirs, ApplicationStatus::PENDING);
        $this->application($theirs, ApplicationStatus::ACCEPTED);
        SavedScholarship::create(['user_id' => $this->student->user_id, 'opportunity_id' => $mine->opportunity_id, 'saved_at' => now()]);
        SavedScholarship::create(['user_id' => $this->student->user_id, 'opportunity_id' => $theirs->opportunity_id, 'saved_at' => now()]);

        $after = app(ProviderAnalyticsService::class)->overview($this->provider);

        $this->assertSame(1, $after['listings'] - $before['listings'], 'one live listing; the draft and the other provider\'s are not counted');
        $this->assertSame(40, $after['views'] - $before['views'], 'a draft\'s views are not counted either');
        $this->assertSame(1, $after['saves'] - $before['saves']);
        $this->assertSame(4, $after['applications'] - $before['applications']);
        $this->assertSame(1, $after['pending'] - $before['pending']);
        $this->assertSame(1, $after['accepted'] - $before['accepted']);
        $this->assertSame(1, $after['rejected'] - $before['rejected']);
    }

    public function test_the_page_shows_the_same_figures_and_only_to_a_provider(): void
    {
        $mine = $this->listing($this->provider, ['title' => 'Counted Award', 'view_count' => 12345]);
        $this->application($mine, ApplicationStatus::PENDING);

        $this->actingAs($this->provider)->get('/provider/analytics')->assertOk()->assertSee('Counted Award')->assertSee('12,345');

        foreach ([$this->student, $this->admin] as $other) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->actingAs($other)->get('/provider/analytics')->assertForbidden();
        }

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->otherProvider)->get('/provider/analytics')->assertOk()->assertDontSee('Counted Award');
    }

    // ----------------------------------------------------------- the admin's charts --

    public function test_the_status_mix_equals_a_direct_count_of_every_status(): void
    {
        $listing = $this->listing($this->provider);

        foreach ([ApplicationStatus::PENDING, ApplicationStatus::PENDING, ApplicationStatus::ACCEPTED, ApplicationStatus::REJECTED, ApplicationStatus::WITHDRAWN] as $status) {
            $this->application($listing, $status);
        }

        $mix = app(AnalyticsService::class)->applicationStatusMix();
        $expected = Application::query()->get()->groupBy('application_status')->map->count();

        $this->assertSame((int) $expected->sum(), array_sum($mix['data']), 'nothing is dropped or counted twice');

        foreach ($expected as $status => $count) {
            $position = array_search(ApplicationStatus::displayLabel($status), $mix['labels'], true);

            $this->assertNotFalse($position, "$status has a slice");
            $this->assertSame($count, $mix['data'][$position], "$status");
        }
    }

    public function test_applications_per_month_land_in_the_right_month_and_old_ones_fall_out_of_the_window(): void
    {
        $listing = $this->listing($this->provider);
        $base = Application::where('submitted_at', '>=', '2025-11-01')->get()->groupBy(fn ($a) => $a->submitted_at->format('Y-m'))->map->count();

        $this->application($listing, ApplicationStatus::PENDING, '2026-10-01 00:00:00');   // first moment of the month
        $this->application($listing, ApplicationStatus::PENDING, '2026-10-22 09:00:00');
        $this->application($listing, ApplicationStatus::PENDING, '2026-09-30 23:59:59');   // last moment of the month before
        $this->application($listing, ApplicationStatus::PENDING, '2025-11-01 00:00:00');   // the first month of a 12-month window
        $this->application($listing, ApplicationStatus::PENDING, '2025-10-31 23:59:59');   // the month before the window

        $series = app(AnalyticsService::class)->applicationsPerMonth(12);
        $by = array_combine($series['labels'], $series['data']);

        $this->assertCount(12, $series['labels']);
        $this->assertSame('Nov 2025', $series['labels'][0]);
        $this->assertSame('Oct 2026', $series['labels'][11]);
        $this->assertSame(($base['2026-10'] ?? 0) + 2, $by['Oct 2026']);
        $this->assertSame(($base['2026-09'] ?? 0) + 1, $by['Sep 2026']);
        $this->assertSame(($base['2025-11'] ?? 0) + 1, $by['Nov 2025']);
        $this->assertArrayNotHasKey('Oct 2025', $by, 'older than the window');
    }

    public function test_top_providers_count_non_draft_listings_and_all_their_applications(): void
    {
        $name = 'Zeta Analytics Trust';
        $a = $this->listing($this->provider, ['provider_name' => $name]);
        $b = $this->listing($this->provider, ['provider_name' => $name]);
        $this->listing($this->provider, ['provider_name' => $name, 'moderation_status' => OpportunityModerationStatus::DRAFT]);
        $this->application($a, ApplicationStatus::PENDING);
        $this->application($a, ApplicationStatus::ACCEPTED);
        $this->application($b, ApplicationStatus::REJECTED);

        $row = app(AnalyticsService::class)->topProviders(1000)->firstWhere('provider_name', $name);

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->listings, 'the draft is not a listing');
        $this->assertSame(3, (int) $row->applications);
    }

    public function test_the_admin_analytics_page_is_for_admins_only(): void
    {
        $this->actingAs($this->admin)->get('/admin/analytics')->assertOk();

        foreach ([$this->provider, $this->student] as $other) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->actingAs($other)->get('/admin/analytics')->assertForbidden();
        }
    }

    // ------------------------------------------------------------- the exports --

    public function test_each_spreadsheet_has_one_row_per_record_and_the_right_statuses(): void
    {
        $live = $this->listing($this->provider, ['title' => 'Export Live Award']);
        $pending = $this->listing($this->provider, ['title' => 'Export Pending Award', 'moderation_status' => OpportunityModerationStatus::PENDING]);
        $rejected = $this->listing($this->provider, ['title' => 'Export Rejected Award', 'moderation_status' => OpportunityModerationStatus::REJECTED]);
        $this->listing($this->provider, ['title' => 'Export Draft Award', 'moderation_status' => OpportunityModerationStatus::DRAFT]);
        $this->application($live, ApplicationStatus::ACCEPTED);

        $service = app(ExcelReportService::class);

        $users = $this->sheet($service->usersExcel());
        $this->assertSame(['Full Name', 'Email', 'Phone', 'Role', 'Status'], $users[0]);
        $this->assertCount(User::count(), array_slice($users, 1));

        $opportunities = $this->sheet($service->opportunitiesExcel());
        $this->assertCount(Opportunity::notDraft()->count(), array_slice($opportunities, 1), 'drafts are not exported');
        $titles = array_column($opportunities, 0);
        $this->assertNotContains('Export Draft Award', $titles);

        $status = fn (string $title) => $opportunities[array_search($title, $titles, true)][7];
        $this->assertSame('Published', $status('Export Live Award'));
        $this->assertSame('Awaiting review', $status('Export Pending Award'), 'a listing waiting for review is not exported as active');
        $this->assertSame('Declined', $status('Export Rejected Award'));

        $applications = $this->sheet($service->applicationsExcel());
        $this->assertCount(Application::count(), array_slice($applications, 1));
    }

    public function test_a_cell_that_looks_like_a_formula_is_exported_as_plain_text(): void
    {
        $this->listing($this->provider, ['title' => '=HYPERLINK("http://evil.example","click")']);

        $rows = $this->sheet(app(ExcelReportService::class)->opportunitiesExcel());
        $titles = array_column($rows, 0);

        $this->assertContains('=HYPERLINK("http://evil.example","click")', $titles, 'the text is kept, not evaluated');
    }

    public function test_the_exports_are_closed_to_everyone_but_an_admin(): void
    {
        foreach (['/admin/reports/users.xlsx', '/admin/reports/opportunities.xlsx', '/admin/reports/applications.xlsx', '/admin/reports/users.pdf', '/admin/reports/applications.pdf'] as $url) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->get($url)->assertRedirect('/login');

            foreach ([$this->student, $this->provider] as $other) {
                $this->flushSession();
                $this->app['auth']->forgetGuards();
                $this->actingAs($other)->get($url)->assertForbidden();
            }

            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }
}
