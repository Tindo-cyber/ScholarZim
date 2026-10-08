<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\ListingRiskChecker;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * When a listing's words disagree with its own settings.
 *
 * The structured setting is what students are checked against. If the description
 * says something else, the provider is told when they post - so they can fix
 * whichever is wrong - and the moderator is shown it, because a listing that
 * contradicts itself is one a reviewer should read before it goes live.
 */
class DescriptionConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->provider->providerProfile->update(['trusted_override' => true]);
        $this->actingAs($this->provider);
    }

    public function test_a_conflict_is_flagged_for_the_moderator_and_forces_review_even_for_a_trusted_provider(): void
    {
        $this->submit([
            'title' => 'Conflicted Award',
            'education_level' => EducationLevel::MASTERS,
            'description' => 'Open to undergraduate students.',
        ])->assertSessionHasNoErrors();

        $listing = Opportunity::where('title', 'Conflicted Award')->firstOrFail();

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status, 'reviewed first, trusted or not');
        $this->assertContains(ListingRiskChecker::DESCRIPTION_CONFLICT, array_column($listing->risk_flags, 'code'));
    }

    public function test_the_moderator_is_shown_the_conflict(): void
    {
        $this->submit(['title' => 'Conflicted Award', 'education_level' => EducationLevel::MASTERS, 'description' => 'Open to undergraduate students.']);
        $listing = Opportunity::where('title', 'Conflicted Award')->firstOrFail();

        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
        $this->flushSession();

        $this->actingAs($admin)->get(route('admin.moderation.show', $listing->opportunity_id))
            ->assertOk()
            ->assertSee('Flagged for review')
            ->assertSee('Masters');
    }

    public function test_the_provider_is_warned_when_they_post(): void
    {
        $this->submit(['title' => 'Conflicted Award', 'education_level' => EducationLevel::MASTERS, 'description' => 'Open to undergraduate students.']);

        $warnings = session('listingWarnings');

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('undergraduate', strtolower($warnings[0]));
        $this->assertStringContainsString('Masters', $warnings[0]);
    }

    public function test_the_warning_is_shown_on_the_dashboard_once(): void
    {
        $this->submit(['title' => 'Conflicted Award', 'education_level' => EducationLevel::MASTERS, 'description' => 'Open to undergraduate students.']);

        $this->get(route('provider.dashboard'))->assertOk()->assertSee('Check this listing')->assertSee('Your description mentions')->assertSee('Masters');
        $this->get(route('provider.dashboard'))->assertDontSee('Check this listing');
    }

    public function test_an_edit_that_creates_a_conflict_warns_and_flags_too(): void
    {
        $this->submit(['title' => 'Consistent Award', 'education_level' => EducationLevel::MASTERS, 'description' => "Open to master's students."]);
        $listing = Opportunity::where('title', 'Consistent Award')->firstOrFail();
        $this->assertNull($listing->risk_flags);

        $this->put('/opportunities/' . $listing->opportunity_id, [
            'title' => 'Consistent Award', 'education_level' => EducationLevel::MASTERS, 'country' => 'Zimbabwe',
            'description' => 'Open to undergraduate students.', 'funding_type' => 'Full Scholarship',
            'deadline' => $listing->deadline?->toDateString(), 'reason' => 'Reworded.',
        ])->assertSessionHasNoErrors();

        $this->assertContains(ListingRiskChecker::DESCRIPTION_CONFLICT, array_column($listing->fresh()->risk_flags, 'code'));
        $this->assertNotEmpty(session('listingWarnings'));
    }

    public function test_fixing_the_conflict_clears_the_flag(): void
    {
        $this->submit(['title' => 'Conflicted Award', 'education_level' => EducationLevel::MASTERS, 'description' => 'Open to undergraduate students.']);
        $listing = Opportunity::where('title', 'Conflicted Award')->firstOrFail();

        $this->put('/opportunities/' . $listing->opportunity_id, [
            'title' => 'Conflicted Award', 'education_level' => EducationLevel::MASTERS, 'country' => 'Zimbabwe',
            'description' => "Open to master's students.", 'funding_type' => 'Full Scholarship',
            'deadline' => $listing->deadline?->toDateString(), 'reason' => 'Fixed.',
        ])->assertSessionHasNoErrors();

        $this->assertNull($listing->fresh()->risk_flags);
        $this->assertEmpty(session('listingWarnings'));
    }

    public function test_a_consistent_listing_raises_nothing(): void
    {
        $this->submit(['title' => 'Fine Award', 'education_level' => EducationLevel::UNDERGRADUATE, 'description' => 'Open to undergraduate students.']);

        $listing = Opportunity::where('title', 'Fine Award')->firstOrFail();

        $this->assertNull($listing->risk_flags);
        $this->assertEmpty(session('listingWarnings'));
        $this->assertSame(OpportunityModerationStatus::APPROVED, $listing->moderation_status, 'a trusted provider\'s clean listing still goes live');
    }

    public function test_the_preview_lists_the_conflicts(): void
    {
        $this->post(route('opportunities.preview'), [
            'title' => 'Previewed', 'education_level' => EducationLevel::MASTERS, 'description' => 'Open to undergraduate students.',
        ])->assertOk()->assertSee('Check this listing')->assertSee('Masters');
    }

    public function test_the_preview_of_a_consistent_listing_has_no_warning(): void
    {
        $this->post(route('opportunities.preview'), [
            'title' => 'Previewed', 'education_level' => EducationLevel::MASTERS, 'description' => "Open to master's students.",
        ])->assertOk()->assertDontSee('Check this listing');
    }

    public function test_the_structured_field_wins_in_what_students_see(): void
    {
        // The description says Law; the setting says Engineering. A Law student is not matched on the description.
        $this->submit(['title' => 'Field Conflict', 'education_level' => EducationLevel::UNDERGRADUATE, 'target_field' => 'Engineering', 'description' => 'Open to students of Law.']);
        $listing = Opportunity::where('title', 'Field Conflict')->firstOrFail();

        $this->assertSame('Engineering', $listing->target_field);
        $this->assertContains(ListingRiskChecker::DESCRIPTION_CONFLICT, array_column($listing->risk_flags, 'code'));
    }

    // ------------------------------------------------------------- helpers --

    private function submit(array $overrides)
    {
        $this->flushSession();

        return $this->post('/opportunities/create', array_merge([
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30)->toDateString(),
        ], $overrides));
    }
}
