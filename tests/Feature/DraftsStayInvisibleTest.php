<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\SavedScholarship;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AnalyticsService;
use App\Services\ExcelReportService;
use App\Services\OpportunityService;
use App\Services\PlatformStatsService;
use App\Services\ProviderAnalyticsService;
use App\Services\ProviderService;
use App\Services\RecommendationService;
use App\Services\ReportService;
use App\Support\EducationLevel;
use App\Support\ListingDefaults;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ReportReason;
use App\Support\ReportStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * A draft belongs to its provider and to nobody else.
 *
 * Every place the platform counts, lists, searches, reminds about or exports
 * listings is named here, one test each, because a draft that leaks through any
 * one of them is somebody's half-finished and possibly embarrassing work on show,
 * or a number on the admin dashboard that is quietly wrong. The last test is the
 * blunt one: a draft with an unmistakable title, and every page a guest, a
 * student and an administrator can reach that lists listings.
 *
 * Each query test measures before and after the draft exists, so it fails if the
 * draft changes the answer - not merely if it happens to be absent.
 */
class DraftsStayInvisibleTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'Zebra Secret Draft';

    private const FIELD = 'ZebraOnlyField';

    private User $provider;

    private User $other;

    private User $student;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        Cache::flush();
    }

    // ============================================================ the queries

    public function test_admin_search_does_not_find_a_draft(): void
    {
        $this->draft();

        $this->actingAs($this->admin)
            ->get(route('admin.search', ['q' => 'Zebra Secret']))
            ->assertOk()
            ->assertDontSee(self::TITLE);
    }

    public function test_the_analytics_series_do_not_count_a_draft(): void
    {
        $service = app(AnalyticsService::class);
        $before = $service->opportunitiesPerMonth();

        $this->draft(['created_at' => Carbon::now()]);

        $this->assertSame($before, $service->opportunitiesPerMonth(), 'listings created per month');
    }

    public function test_top_providers_does_not_count_a_draft_or_rank_a_provider_with_only_one(): void
    {
        $service = app(AnalyticsService::class);

        $this->draft(['provider_user_id' => $this->other->user_id, 'provider_name' => $this->other->full_name]);

        $this->assertNotContains($this->other->full_name, $service->topProviders()->pluck('provider_name')->all());

        $before = $service->topProviders()->firstWhere('provider_name', $this->provider->full_name)?->listings;
        $this->draft();
        $this->assertSame($before, $service->topProviders()->firstWhere('provider_name', $this->provider->full_name)?->listings);
    }

    public function test_the_platform_totals_do_not_count_a_draft(): void
    {
        $stats = app(PlatformStatsService::class);
        $before = $stats->adminStats();

        $this->draft();

        $this->assertSame($before, $stats->adminStats());
    }

    public function test_the_providers_dashboard_numbers_do_not_count_a_draft(): void
    {
        $service = app(ProviderService::class);
        $before = $service->dashboardStats($this->provider);

        $this->draft(['deadline' => Carbon::today()->addDays(2)]);

        $this->assertSame($before, $service->dashboardStats($this->provider));
    }

    public function test_upcoming_deadlines_do_not_include_a_draft(): void
    {
        $this->draft(['deadline' => Carbon::today()->addDay()]);

        $this->assertNotContains(self::TITLE, app(ProviderService::class)->upcomingDeadlines($this->provider, 50)->pluck('title')->all());
    }

    public function test_the_provider_still_sees_their_own_draft_in_their_own_list(): void
    {
        $this->draft();

        $this->assertContains(self::TITLE, app(ProviderService::class)->myOpportunities($this->provider)->pluck('title')->all());
        $this->assertContains(self::TITLE, app(OpportunityService::class)->forProvider($this->provider)->pluck('title')->all());
    }

    public function test_provider_analytics_do_not_count_a_draft(): void
    {
        $service = app(ProviderAnalyticsService::class);
        $before = $service->overview($this->provider);

        $this->draft(['view_count' => 500]);
        $after = $service->overview($this->provider);

        $this->assertSame($before['listings'], $after['listings']);
        $this->assertSame($before['views'], $after['views']);
        $this->assertNotContains(self::TITLE, array_column($after['byListing'], 'title'));
    }

    public function test_the_expiry_job_does_not_close_a_draft_or_tell_anyone(): void
    {
        $draft = $this->draft(['deadline' => Carbon::today()->subDays(2)]);
        $before = Notification::where('type', NotificationType::SCHOLARSHIP_CLOSED)->count();

        Artisan::call('scholarzim:archive-expired-opportunities');

        $this->assertSame(OpportunityStatus::ACTIVE, $draft->fresh()->status, 'a draft is not archived');
        $this->assertSame($before, Notification::where('type', NotificationType::SCHOLARSHIP_CLOSED)->count());
    }

    public function test_the_deadline_reminders_never_mention_a_draft(): void
    {
        $draft = $this->draft(['deadline' => Carbon::today()->addDays(3)]);
        SavedScholarship::create(['user_id' => $this->student->user_id, 'opportunity_id' => $draft->opportunity_id, 'saved_at' => Carbon::now()]);

        Artisan::call('scholarzim:deadline-reminders');

        $this->assertSame(0, Notification::where('related_id', $draft->opportunity_id)->count());
    }

    public function test_a_provider_with_only_drafts_can_delete_their_account(): void
    {
        $this->draft(['provider_user_id' => $this->other->user_id, 'provider_name' => $this->other->full_name]);
        $this->assertSame(0, Opportunity::where('provider_user_id', $this->other->user_id)->where('moderation_status', '!=', 'DRAFT')->count(), 'fixture: only drafts');

        app(AccountDeletionService::class)->delete($this->other, $this->other->email, true);

        $this->assertNull(User::find($this->other->user_id));
        $this->assertSame(0, Opportunity::where('title', self::TITLE)->count(), 'their drafts go with the account');
    }

    public function test_a_draft_is_not_offered_as_a_possible_duplicate(): void
    {
        $this->draft(['title' => 'Quokka Bursary 2027']);
        $pending = $this->listing(['title' => 'Quokka Bursary 2027 intake', 'moderation_status' => OpportunityModerationStatus::PENDING]);

        $found = app(OpportunityService::class)->findPotentialDuplicates($pending)->pluck('title')->all();

        $this->assertNotContains('Quokka Bursary 2027', $found, 'a draft is not a duplicate of anything anyone has submitted');
    }

    public function test_the_filter_facets_do_not_learn_from_a_draft(): void
    {
        $this->draft(['target_field' => self::FIELD, 'provider_name' => 'Draft Only Organisation']);
        app(OpportunityService::class)->forgetFacetCaches();

        $this->assertNotContains(self::FIELD, app(OpportunityService::class)->targetFields());
        $this->assertNotContains('Draft Only Organisation', app(OpportunityService::class)->providerNames());
    }

    public function test_a_draft_does_not_shape_the_next_forms_defaults(): void
    {
        // The second demo provider has submitted nothing, so their defaults are empty - unless a draft leaks in.
        $this->draft([
            'provider_user_id' => $this->other->user_id, 'provider_name' => $this->other->full_name,
            'award_currency' => 'EUR', 'award_amount' => 1, 'required_province' => 'Harare',
        ]);

        $this->assertSame([], ListingDefaults::forProvider($this->other->fresh()));
    }

    public function test_recommendations_never_include_a_draft(): void
    {
        $this->draft(['education_level' => EducationLevel::UNDERGRADUATE, 'target_field' => null]);

        // limit 0 means every eligible listing, not the first twelve.
        $titles = array_map(fn ($result) => $result->opportunity->title, app(RecommendationService::class)->forUser($this->student, 0));

        $this->assertNotContains(self::TITLE, $titles);
    }

    public function test_a_draft_cannot_be_reported_and_never_reaches_the_reports_queue(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->student)
            ->post(route('listing.report', $draft->opportunity_id), ['reason' => ReportReason::OTHER])
            ->assertNotFound();

        // Even a stray report row cannot put it in front of the administrators.
        OpportunityReport::create([
            'opportunity_id' => $draft->opportunity_id, 'user_id' => $this->student->user_id,
            'reason' => ReportReason::OTHER, 'status' => ReportStatus::PENDING, 'created_at' => Carbon::now(),
        ]);

        $this->flushSession();
        $this->actingAs($this->admin)->get(route('admin.listing-reports'))->assertOk()->assertDontSee(self::TITLE);
        $this->assertSame(0, \App\Models\Opportunity::whereHas('reports')->notDraft()->count());
    }

    public function test_the_moderation_queues_never_hold_a_draft(): void
    {
        $this->draft();

        $moderation = app(\App\Services\OpportunityModerationService::class);

        $this->assertNotContains(self::TITLE, $moderation->pendingQueue()->pluck('title')->all());
        $this->assertNotContains(self::TITLE, $moderation->autoPublishedQueue()->pluck('title')->all());
        $this->assertSame($moderation->pendingQueue()->count(), $moderation->pendingCount());
    }

    public function test_the_excel_export_leaves_a_draft_out(): void
    {
        $this->draft();

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, app(ExcelReportService::class)->opportunitiesExcel());
        $sheet = IOFactory::createReaderForFile($path)->load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $this->assertNotContains(self::TITLE, array_column($sheet, 0));
    }

    public function test_the_pdf_export_is_given_no_draft(): void
    {
        $this->draft();
        $given = null;

        View::composer('reports.opportunities', function ($view) use (&$given) {
            $given = $view->getData()['opportunities'] ?? null;
        });

        app(ReportService::class)->opportunitiesReportPdf();

        $this->assertNotNull($given, 'the report view must have been rendered');
        $this->assertNotContains(self::TITLE, collect($given)->pluck('title')->all());
    }

    // ============================================================ the blunt one

    public function test_no_draft_appears_anywhere_public_or_admin(): void
    {
        $draft = $this->draft(['deadline' => Carbon::today()->addDays(5)]);
        SavedScholarship::create(['user_id' => $this->student->user_id, 'opportunity_id' => $draft->opportunity_id, 'saved_at' => Carbon::now()]);
        OpportunityReport::create([
            'opportunity_id' => $draft->opportunity_id, 'user_id' => $this->student->user_id,
            'reason' => ReportReason::OTHER, 'status' => ReportStatus::PENDING, 'created_at' => Carbon::now(),
        ]);
        app(OpportunityService::class)->forgetFacetCaches();

        $everyone = ['/', '/scholarships', '/scholarships?keyword=Zebra', '/scholarships?field_of_study=' . self::FIELD, '/scholarships?sort=deadline'];
        $students = ['/dashboard', '/opportunities', '/opportunities?keyword=Zebra', '/applicant/dashboard', '/applicant/recommendations', '/applicant/saved', '/applicant/profile'];
        $admins = ['/admin/dashboard', '/admin/search?q=Zebra', '/admin/analytics', '/admin/reports', '/admin/auto-published', '/admin/listing-reports', '/admin/users', '/admin/audit'];
        $otherProvider = ['/provider/dashboard', '/provider/analytics', '/provider/applications'];

        $leaks = [];

        $check = function (string $who, array $urls) use (&$leaks) {
            foreach ($urls as $url) {
                $response = $this->get($url);

                if ($response->status() !== 200) {
                    continue;
                }

                $html = $response->getContent();

                foreach ([self::TITLE, self::FIELD] as $needle) {
                    // A filter page echoes the filter it was asked for; that is the query string, not a listing.
                    if (str_contains($url, $needle)) {
                        continue;
                    }

                    if (str_contains($html, $needle)) {
                        $leaks[] = "$who: $url shows \"$needle\"";
                    }
                }
            }
        };

        Auth::logout();
        $this->flushSession();
        $check('guest', $everyone);

        $this->actingAs($this->student);
        $check('student', array_merge($everyone, $students));

        $this->flushSession();
        $this->actingAs($this->admin);
        $check('admin', array_merge($everyone, $admins));

        $this->flushSession();
        $this->actingAs($this->other);
        $check('another provider', array_merge($everyone, $otherProvider));

        $this->assertSame([], $leaks, "a draft leaked:\n" . implode("\n", $leaks));

        // And the one place it SHOULD appear does.
        $this->flushSession();
        $this->actingAs($this->provider);
        $this->get('/provider/dashboard')->assertOk()->assertSee(self::TITLE);

        // Its own public address does not exist.
        Auth::logout();
        $this->flushSession();
        $this->get('/scholarships/' . $draft->opportunity_id)->assertNotFound();
    }

    // ------------------------------------------------------------- helpers --

    private function draft(array $attributes = []): Opportunity
    {
        return $this->listing(array_merge([
            'title' => self::TITLE,
            'target_field' => self::FIELD,
            'moderation_status' => OpportunityModerationStatus::DRAFT,
            'submitted_at' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ], $attributes));
    }

    private function listing(array $attributes = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => $this->provider->full_name,
            'title' => 'Visibility Fixture ' . uniqid(),
            'description' => 'A fixture listing.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDays(5),
            'reviewed_at' => Carbon::now()->subDays(4),
            'reviewed_by' => 'admin@scholarzim.co.zw',
        ], $attributes));
    }
}
