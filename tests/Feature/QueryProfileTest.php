<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\NotificationType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Query counts for the pages that render lists.
 *
 * An N+1 does not fail anything - the page renders, the tests pass, and the cost
 * only shows up once real data arrives. These budgets exist so a lazily-loaded
 * relation added inside a Blade @foreach fails here instead of on a provider's
 * dashboard with four hundred applications on it.
 *
 * The numbers are ceilings with headroom, not exact counts: they are meant to
 * catch a query issued *per row*, which is the bug worth catching, and to be
 * stable against an extra constant-cost lookup somewhere.
 */
class QueryProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    /**
     * Counts queries for one request, then asserts the count does not grow with
     * the number of rows on the page - which is the actual definition of the
     * bug, and something a single fixed budget cannot express on its own.
     */
    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_provider_inbox_does_not_query_per_application(): void
    {
        $this->seedApplications(3);
        $small = $this->countQueries(fn () => $this->actingAs($this->provider)->get('/provider/applications')->assertOk());

        $this->seedApplications(20);
        $large = $this->countQueries(fn () => $this->actingAs($this->provider)->get('/provider/applications')->assertOk());

        $this->assertLessThanOrEqual(
            $small + 2,
            $large,
            "the inbox issued {$small} queries for 3 applications and {$large} for 23 - that is a query per row"
        );
    }

    public function test_my_applications_does_not_query_per_application(): void
    {
        $this->seedApplicationsForStudent(3);
        $small = $this->countQueries(fn () => $this->actingAs($this->student)->get('/my-applications')->assertOk());

        $this->seedApplicationsForStudent(20);
        $large = $this->countQueries(fn () => $this->actingAs($this->student)->get('/my-applications')->assertOk());

        $this->assertLessThanOrEqual($small + 2, $large, 'my-applications scales with row count');
    }

    public function test_the_notification_list_does_not_query_per_notification(): void
    {
        $this->seedNotifications(3);
        $small = $this->countQueries(fn () => $this->actingAs($this->student)->get('/notifications')->assertOk());

        $this->seedNotifications(30);
        $large = $this->countQueries(fn () => $this->actingAs($this->student)->get('/notifications')->assertOk());

        $this->assertLessThanOrEqual($small + 2, $large, 'the notification list scales with row count');
    }

    public function test_the_public_catalogue_does_not_query_per_listing(): void
    {
        $small = $this->countQueries(fn () => $this->get('/scholarships')->assertOk());

        $this->seedOpportunities(25);

        $large = $this->countQueries(fn () => $this->get('/scholarships')->assertOk());

        $this->assertLessThanOrEqual(
            $small + 3,
            $large,
            "the catalogue issued {$small} queries then {$large} with 25 more listings"
        );
    }

    public function test_the_admin_moderation_queue_does_not_query_per_listing(): void
    {
        $small = $this->countQueries(fn () => $this->actingAs($this->admin)->get('/admin/dashboard')->assertOk());

        $this->seedOpportunities(20, pending: true);

        $large = $this->countQueries(fn () => $this->actingAs($this->admin)->get('/admin/dashboard')->assertOk());

        $this->assertLessThanOrEqual(
            $small + 25,
            $large,
            "the moderation queue issued {$small} then {$large} queries - the duplicate check runs per listing by design, "
                . 'but it must not also lazily load each provider'
        );
    }

    public function test_the_applicant_dashboard_does_not_query_per_recommendation(): void
    {
        $before = $this->countQueries(fn () => $this->actingAs($this->student)->get('/applicant/dashboard')->assertOk());

        $this->seedOpportunities(25);

        $after = $this->countQueries(fn () => $this->actingAs($this->student)->get('/applicant/dashboard')->assertOk());

        $this->assertLessThanOrEqual(
            $before + 4,
            $after,
            "the dashboard issued {$before} then {$after} queries against a larger catalogue"
        );
    }

    // --------------------------------------------------------------- helpers --

    private function seedApplications(int $count): void
    {
        $opportunity = Opportunity::where('provider_user_id', $this->provider->user_id)->firstOrFail();

        for ($i = 0; $i < $count; $i++) {
            $applicant = User::create([
                'role_id' => $this->student->role_id,
                'full_name' => 'Applicant ' . uniqid(),
                'email' => uniqid('applicant') . '@example.test',
                'password_hash' => bcrypt('ChangeMe123'),
                'account_status' => \App\Support\AccountStatus::ACTIVE,
                'email_verified' => true,
            ]);

            Application::create([
                'user_id' => $applicant->user_id,
                'opportunity_id' => $opportunity->opportunity_id,
                'application_status' => ApplicationStatus::PENDING,
                'submitted_at' => Carbon::now()->subDays($i),
            ]);
        }
    }

    private function seedApplicationsForStudent(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $opportunity = $this->makeOpportunity('Listing for student ' . uniqid());

            Application::create([
                'user_id' => $this->student->user_id,
                'opportunity_id' => $opportunity->opportunity_id,
                'application_status' => ApplicationStatus::PENDING,
                'submitted_at' => Carbon::now()->subDays($i),
            ]);
        }
    }

    private function seedNotifications(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Notification::create([
                'user_id' => $this->student->user_id,
                'type' => NotificationType::APPLICATION_ACCEPTED,
                'message' => 'Message ' . $i,
                'link' => '/my-applications',
                'is_read' => false,
                'created_at' => Carbon::now()->subMinutes($i),
            ]);
        }
    }

    private function seedOpportunities(int $count, bool $pending = false): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeOpportunity('Profiling Listing ' . uniqid(), $pending);
        }
    }

    private function makeOpportunity(string $title, bool $pending = false): Opportunity
    {
        return Opportunity::create([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => $this->provider->full_name,
            'title' => $title,
            'description' => 'A listing used for query profiling.',
            'education_level' => 'Undergraduate',
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => \App\Support\OpportunityStatus::ACTIVE,
            'moderation_status' => $pending
                ? \App\Support\OpportunityModerationStatus::PENDING
                : \App\Support\OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(),
        ]);
    }
}
