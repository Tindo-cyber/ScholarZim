<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\OpportunityModerationService;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Saved searches and the daily alert job they drive. */
class SavedSearchAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    public function test_a_student_saves_the_search_they_are_looking_at(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/saved-searches', [
                'name' => 'Undergraduate computing',
                'education_level' => 'Undergraduate',
                'field_of_study' => 'Computer Science & IT',
                'alerts_enabled' => '1',
                // Not a filter, and must not be stored as one.
                'sort' => 'deadline',
            ])
            ->assertRedirect();

        $search = SavedSearch::where('user_id', $this->student->user_id)->firstOrFail();

        $this->assertSame('Undergraduate computing', $search->name);
        $this->assertSame('Undergraduate', $search->filters['education_level']);
        $this->assertArrayNotHasKey('sort', $search->filters);
    }

    /**
     * Saving a search must not queue up the entire back catalogue for tomorrow
     * morning: everything already published counts as seen.
     */
    public function test_saving_a_search_does_not_alert_about_existing_listings(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/saved-searches', ['name' => 'Everything'])
            ->assertRedirect();

        $this->artisan('scholarzim:search-alerts')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->student->user_id,
            'type' => NotificationType::SCHOLARSHIP_SEARCH_MATCH,
        ]);
    }

    public function test_a_newly_published_match_produces_exactly_one_alert(): void
    {
        $this->saveSearch(['field_of_study' => 'Computer Science & IT']);

        $this->publishListing('Fresh Computing Bursary', 'Computer Science & IT');

        $this->artisan('scholarzim:search-alerts')->assertSuccessful();

        $this->assertSame(1, $this->alertCount());

        // Idempotent: the high-water mark means a second run has nothing new.
        $this->artisan('scholarzim:search-alerts')->assertSuccessful();

        $this->assertSame(1, $this->alertCount());
    }

    public function test_a_listing_outside_the_filters_produces_no_alert(): void
    {
        $this->saveSearch(['field_of_study' => 'Computer Science & IT']);

        $this->publishListing('Mining Diploma Award', 'Mining & Metallurgy');

        $this->artisan('scholarzim:search-alerts')->assertSuccessful();

        $this->assertSame(0, $this->alertCount());
    }

    public function test_turning_alerts_off_stops_the_job_from_writing(): void
    {
        $search = $this->saveSearch(['field_of_study' => 'Computer Science & IT']);
        $search->update(['alerts_enabled' => false]);

        $this->publishListing('Another Computing Bursary', 'Computer Science & IT');

        $this->artisan('scholarzim:search-alerts')->assertSuccessful();

        $this->assertSame(0, $this->alertCount());
    }

    public function test_a_student_cannot_touch_someone_elses_saved_search(): void
    {
        $search = $this->saveSearch([]);

        $other = User::create([
            'role_id' => $this->student->role_id,
            'full_name' => 'Someone Else',
            'email' => 'someone-else@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => \App\Support\AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);

        $this->actingAs($other)
            ->delete('/applicant/saved-searches/' . $search->saved_search_id)
            ->assertNotFound();

        $this->assertDatabaseHas('saved_searches', ['saved_search_id' => $search->saved_search_id]);
    }

    private function saveSearch(array $filters): SavedSearch
    {
        return SavedSearch::create([
            'user_id' => $this->student->user_id,
            'name' => 'Test search',
            'filters' => $filters,
            'alerts_enabled' => true,
            'last_alerted_opportunity_id' => (int) Opportunity::max('opportunity_id'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /** Published the way the site publishes: through the moderation service. */
    private function publishListing(string $title, string $field): Opportunity
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $opportunity = Opportunity::create([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => $title,
            'description' => 'A newly published listing used by the alert tests.',
            'education_level' => 'Undergraduate',
            'target_field' => $field,
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(45),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::PENDING,
            'submitted_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);

        app(OpportunityModerationService::class)->approve($opportunity->opportunity_id, $admin);

        return $opportunity->fresh();
    }

    private function alertCount(): int
    {
        return \App\Models\Notification::where('user_id', $this->student->user_id)
            ->where('type', NotificationType::SCHOLARSHIP_SEARCH_MATCH)
            ->count();
    }
}
