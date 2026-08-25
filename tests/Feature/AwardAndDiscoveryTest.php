<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** What an award is worth, and the sorting and filtering that depends on it. */
class AwardAndDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    public function test_a_provider_can_post_an_award_value_and_eligibility_rules(): void
    {
        $this->actingAs($this->provider)
            ->post('/opportunities/create', [
                'title' => 'Fully Funded Engineering Award',
                'description' => 'Covers tuition, accommodation, and a monthly stipend for four years.',
                'education_level' => 'Undergraduate',
                'target_field' => 'Engineering',
                'funding_type' => 'Full Scholarship',
                'country' => 'Zimbabwe',
                'award_amount' => '4500',
                'award_currency' => 'USD',
                'award_slots' => '10',
                'is_renewable' => '1',
                'external_url' => 'https://example.test/apply',
                'min_academic_points' => '12',
                'max_age' => '25',
                'required_citizenship' => 'Zimbabwean',
                'requires_results_certificate' => '1',
            ])
            ->assertRedirect('/provider/dashboard');

        $created = Opportunity::where('title', 'Fully Funded Engineering Award')->firstOrFail();

        $this->assertSame('4500.00', $created->award_amount);
        $this->assertSame('USD', $created->award_currency);
        $this->assertSame(10, $created->award_slots);
        $this->assertTrue($created->is_renewable);
        $this->assertSame(12, $created->min_academic_points);
        $this->assertTrue($created->hasEligibilityRules());
        $this->assertSame('USD 4,500', $created->formattedAward());
    }

    /** A blank rule must never be stored as a rule of zero. */
    public function test_blank_award_fields_are_stored_as_no_value_at_all(): void
    {
        $this->actingAs($this->provider)
            ->post('/opportunities/create', [
                'title' => 'Value Not Stated Award',
                'description' => 'A listing whose value depends on assessed need.',
                'country' => 'Zimbabwe',
                'award_amount' => '',
                'min_academic_points' => '',
            ])
            ->assertRedirect();

        $created = Opportunity::where('title', 'Value Not Stated Award')->firstOrFail();

        $this->assertNull($created->award_amount);
        $this->assertNull($created->award_currency);
        $this->assertNull($created->min_academic_points);
        $this->assertFalse($created->hasEligibilityRules());
        $this->assertFalse($created->hasAwardValue());
    }

    public function test_listings_can_be_sorted_by_award_value(): void
    {
        $this->publish('Small Award', 500);
        $this->publish('Large Award', 9000);

        $response = $this->getJson('/api/v1/scholarships?sort=award_desc');

        $titles = collect($response->json('data'))->pluck('title')->all();

        $this->assertSame('Large Award', $titles[0]);
        $this->assertLessThan(
            array_search('Small Award', $titles, true),
            array_search('Large Award', $titles, true)
        );
    }

    /**
     * "At least USD 1,000" cannot honestly include a listing that states no value
     * at all, so those are excluded rather than treated as zero.
     */
    public function test_a_minimum_award_filter_excludes_listings_with_no_stated_value(): void
    {
        $this->publish('Stated Value Award', 2000);

        $titles = collect($this->getJson('/api/v1/scholarships?min_award=1000')->json('data'))
            ->pluck('title')
            ->all();

        $this->assertContains('Stated Value Award', $titles);
        // Every seeded listing leaves its value unstated.
        $this->assertNotContains('Zimbabwe Tech Futures Undergraduate Bursary', $titles);
    }

    public function test_an_unrecognised_sort_falls_back_to_the_default(): void
    {
        $this->get('/scholarships?sort=nonsense')->assertOk();
    }

    public function test_viewing_a_listing_counts_towards_the_provider_funnel(): void
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();

        $this->get('/scholarships/' . $opportunity->opportunity_id)->assertOk();

        $this->assertSame(1, $opportunity->fresh()->view_count);
        $this->assertDatabaseHas('opportunity_views', [
            'opportunity_id' => $opportunity->opportunity_id,
            'views' => 1,
        ]);
    }

    /** A provider looking at their own post is not an audience. */
    public function test_a_providers_own_visit_is_not_counted(): void
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();

        $this->actingAs($this->provider)
            ->get('/scholarships/' . $opportunity->opportunity_id)
            ->assertOk();

        $this->assertSame(0, $opportunity->fresh()->view_count);
    }

    public function test_the_provider_analytics_page_renders(): void
    {
        $this->actingAs($this->provider)
            ->get('/provider/analytics')
            ->assertOk()
            ->assertSee('Applications');
    }

    public function test_an_applicant_cannot_see_provider_analytics(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($student)->get('/provider/analytics')->assertForbidden();
    }

    private function publish(string $title, float $amount): Opportunity
    {
        return Opportunity::create([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => $this->provider->full_name,
            'title' => $title,
            'description' => 'A listing with a stated value, used by the sorting tests.',
            'education_level' => 'Undergraduate',
            'target_field' => 'Engineering',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'award_amount' => $amount,
            'award_currency' => 'USD',
            'deadline' => Carbon::today()->addDays(60),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);
    }
}
