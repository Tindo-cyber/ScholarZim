<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\SavedScholarship;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Objective 1: a student can search, filter and save scholarship opportunities.
 *
 * The catalogue is public, so searching and filtering are asserted as a guest as
 * well as a student; saving needs an account.
 */
class ScholarshipDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------------- search --

    public function test_a_guest_can_browse_the_catalogue(): void
    {
        $this->get('/scholarships')
            ->assertOk()
            ->assertSee($this->listing()->title);
    }

    public function test_a_keyword_search_narrows_the_catalogue(): void
    {
        $match = $this->listing();

        $titles = $this->searchTitles(['keyword' => 'Tech Futures']);

        $this->assertContains($match->title, $titles);
        $this->assertNotContains('Midlands Engineering Excellence Award', $titles);
    }

    public function test_a_search_with_no_matches_returns_nothing_rather_than_everything(): void
    {
        $this->assertSame([], $this->searchTitles(['keyword' => 'Quidditch Bursary For Wizards']));
    }

    // ------------------------------------------------------------- filter --

    public function test_listings_can_be_filtered_by_field_of_study(): void
    {
        $field = $this->listing()->target_field;

        $results = app(\App\Services\OpportunityService::class)
            ->searchAll(['field_of_study' => $field]);

        $this->assertNotEmpty($results);

        foreach ($results as $opportunity) {
            $this->assertSame($field, $opportunity->target_field);
        }
    }

    public function test_listings_can_be_filtered_by_education_level(): void
    {
        $level = $this->listing()->education_level;

        $results = app(\App\Services\OpportunityService::class)
            ->searchAll(['education_level' => $level]);

        $this->assertNotEmpty($results);

        foreach ($results as $opportunity) {
            $this->assertSame($level, $opportunity->education_level);
        }
    }

    public function test_the_filter_page_renders_with_filters_applied(): void
    {
        $this->actingAs($this->student)
            ->get('/opportunities?' . http_build_query([
                'keyword' => 'Tech',
                'education_level' => $this->listing()->education_level,
            ]))
            ->assertOk();
    }

    // --------------------------------------------------------------- save --

    public function test_a_student_can_save_and_unsave_a_scholarship(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->student)
            ->post('/applicant/saved/' . $listing->opportunity_id)
            ->assertRedirect();

        $this->assertDatabaseHas('saved_scholarships', [
            'user_id' => $this->student->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);

        $this->actingAs($this->student)
            ->post('/applicant/saved/' . $listing->opportunity_id . '/remove')
            ->assertRedirect();

        $this->assertDatabaseMissing('saved_scholarships', [
            'user_id' => $this->student->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);
    }

    public function test_saved_scholarships_appear_on_the_saved_page(): void
    {
        $listing = $this->listing();

        SavedScholarship::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);

        $this->actingAs($this->student)
            ->get('/applicant/saved')
            ->assertOk()
            ->assertSee($listing->title);
    }

    /** A guest has nowhere to save to, so the action is behind authentication. */
    public function test_a_guest_cannot_save_a_scholarship(): void
    {
        $this->post('/applicant/saved/' . $this->listing()->opportunity_id)
            ->assertRedirect('/login');
    }

    // ------------------------------------------------------------ helpers --

    private function listing(): Opportunity
    {
        return Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();
    }

    /** @return array<int, string> */
    private function searchTitles(array $filters): array
    {
        return app(\App\Services\OpportunityService::class)
            ->searchAll($filters)
            ->pluck('title')
            ->values()
            ->all();
    }
}
