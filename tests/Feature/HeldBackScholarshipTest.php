<?php

namespace Tests\Feature;

use App\Models\HeldBackScholarship;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\RecommendationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Holding a scholarship back: distinct from SavedScholarship (a watchlist
 * entry that leaves the listing exactly where it was) and distinct from
 * ScholarFit's own "things holding this score back" explanation (never
 * persisted, not an action at all). See HeldBackScholarshipService.
 */
class HeldBackScholarshipTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------ core behaviour --

    public function test_an_applicant_can_hold_back_a_scholarship(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->student)
            ->post('/applicant/held-back/' . $listing->opportunity_id)
            ->assertRedirect();

        $this->assertDatabaseHas('held_back_scholarships', [
            'user_id' => $this->student->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);
    }

    /** The correct applicant owns the record - not a shared or global flag on the listing. */
    public function test_the_held_back_record_is_owned_by_the_acting_applicant(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->student)->post('/applicant/held-back/' . $listing->opportunity_id);

        $record = HeldBackScholarship::where('opportunity_id', $listing->opportunity_id)->firstOrFail();

        $this->assertSame($this->student->user_id, $record->user_id);
    }

    public function test_a_held_back_scholarship_appears_on_the_held_back_page(): void
    {
        $listing = $this->listing();

        HeldBackScholarship::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);

        $this->actingAs($this->student)
            ->get('/applicant/held-back')
            ->assertOk()
            ->assertSee($listing->title);
    }

    public function test_holding_back_can_be_undone(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->student)->post('/applicant/held-back/' . $listing->opportunity_id);

        $this->actingAs($this->student)
            ->post('/applicant/held-back/' . $listing->opportunity_id . '/remove')
            ->assertRedirect();

        $this->assertDatabaseMissing('held_back_scholarships', [
            'user_id' => $this->student->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);
    }

    /** A guest has nowhere to hold back to, so the action is behind authentication. */
    public function test_a_guest_cannot_hold_back_a_scholarship(): void
    {
        $this->post('/applicant/held-back/' . $this->listing()->opportunity_id)
            ->assertRedirect('/login');

        $this->assertDatabaseMissing('held_back_scholarships', [
            'opportunity_id' => $this->listing()->opportunity_id,
        ]);
    }

    // --------------------------------------------------------------- security --

    public function test_an_applicant_cannot_see_another_applicants_held_back_scholarships(): void
    {
        $other = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $listing = $this->listing();

        HeldBackScholarship::create([
            'user_id' => $other->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);

        $this->actingAs($this->student)
            ->get('/applicant/held-back')
            ->assertOk()
            ->assertDontSee($listing->title);
    }

    /** Removing a hold-back only ever touches the acting applicant's own row, by construction of the query. */
    public function test_an_applicant_cannot_remove_another_applicants_held_back_scholarship(): void
    {
        $other = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $listing = $this->listing();

        HeldBackScholarship::create([
            'user_id' => $other->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);

        $this->actingAs($this->student)
            ->post('/applicant/held-back/' . $listing->opportunity_id . '/remove')
            ->assertRedirect();

        $this->assertDatabaseHas('held_back_scholarships', [
            'user_id' => $other->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);
    }

    // -------------------------------------------------- effect on matches --

    /**
     * The one behaviour distinguishing this from Save: once held back, the
     * listing stops appearing as an active recommendation - see
     * RecommendationService::rankedCandidates() - until it is released.
     */
    public function test_a_held_back_scholarship_no_longer_appears_as_an_active_match(): void
    {
        $service = app(RecommendationService::class);
        $listing = $this->recommendedOpportunity();

        $this->assertContains($listing->opportunity_id, $this->rankedIds($service));

        HeldBackScholarship::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);

        $this->assertNotContains($listing->opportunity_id, $this->rankedIds($service));
    }

    /** Releasing it restores it to active matches - nothing about the listing itself changed. */
    public function test_releasing_a_held_back_scholarship_restores_it_to_active_matches(): void
    {
        $service = app(RecommendationService::class);
        $listing = $this->recommendedOpportunity();

        $this->actingAs($this->student)->post('/applicant/held-back/' . $listing->opportunity_id);
        $this->assertNotContains($listing->opportunity_id, $this->rankedIds($service));

        $this->actingAs($this->student)->post('/applicant/held-back/' . $listing->opportunity_id . '/remove');
        $this->assertContains($listing->opportunity_id, $this->rankedIds($service));
    }

    /** Held back is not saved, and saved is not held back - independent states on the same listing. */
    public function test_holding_back_is_independent_of_saving(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->student)->post('/applicant/saved/' . $listing->opportunity_id);
        $this->actingAs($this->student)->post('/applicant/held-back/' . $listing->opportunity_id);

        $this->assertDatabaseHas('saved_scholarships', [
            'user_id' => $this->student->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);
        $this->assertDatabaseHas('held_back_scholarships', [
            'user_id' => $this->student->user_id,
            'opportunity_id' => $listing->opportunity_id,
        ]);
    }

    // ------------------------------------------------------------ helpers --

    private function listing(): Opportunity
    {
        return Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();
    }

    /** A listing this seeded student is actually recommended, for the active-matches tests. */
    private function recommendedOpportunity(): Opportunity
    {
        $ids = $this->rankedIds(app(RecommendationService::class));

        $this->assertNotEmpty($ids, 'the seeded student must have at least one recommendation');

        return Opportunity::findOrFail($ids[0]);
    }

    /** @return array<int, int> */
    private function rankedIds(RecommendationService $service): array
    {
        return array_map(
            static fn ($scored) => (int) $scored->opportunity->opportunity_id,
            $service->forUser($this->student->refresh(), 0)
        );
    }
}
