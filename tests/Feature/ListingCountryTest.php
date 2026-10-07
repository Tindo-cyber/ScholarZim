<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A listing held outside Zimbabwe: how public search finds it and how its card
 * and page describe it. Saving the country is covered in ListingValidationTest.
 */
class ListingCountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_search_can_filter_to_one_country(): void
    {
        $this->listing('Berlin Engineering Award', 'Germany');
        $this->listing('Harare Engineering Award', 'Zimbabwe');

        $this->get('/scholarships?country=Germany')
            ->assertOk()
            ->assertSee('Berlin Engineering Award')
            ->assertDontSee('Harare Engineering Award');

        $this->get('/scholarships?country=Zimbabwe')
            ->assertOk()
            ->assertSee('Harare Engineering Award')
            ->assertDontSee('Berlin Engineering Award');
    }

    public function test_no_country_filter_shows_every_country(): void
    {
        $this->listing('Berlin Engineering Award', 'Germany');
        $this->listing('Harare Engineering Award', 'Zimbabwe');

        $this->get('/scholarships')
            ->assertSee('Berlin Engineering Award')
            ->assertSee('Harare Engineering Award');
    }

    public function test_the_filter_bar_offers_the_countries(): void
    {
        $html = $this->get('/scholarships')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<select[^>]*name="country"#', $html);
        $this->assertStringContainsString('<option value="Germany"', $html);
    }

    public function test_a_listing_held_abroad_says_so_on_its_card_and_page(): void
    {
        $abroad = $this->listing('Berlin Engineering Award', 'Germany');

        $this->get('/scholarships?country=Germany')->assertSee('Germany');
        $this->get('/scholarships/' . $abroad->opportunity_id)->assertOk()->assertSee('Germany');
    }

    public function test_location_label_reads_naturally(): void
    {
        $this->assertSame('Gweru', $this->make('Zimbabwe', 'Gweru', 'Midlands')->locationLabel());
        $this->assertSame('Midlands', $this->make('Zimbabwe', null, 'Midlands')->locationLabel());
        $this->assertNull($this->make('Zimbabwe', null, null)->locationLabel(), 'a Zimbabwean listing with no place shows none');
        $this->assertNull($this->make(null, null, null)->locationLabel(), 'a legacy row with no country is treated as Zimbabwe');
        $this->assertSame('Germany', $this->make('Germany', null, null)->locationLabel());
        $this->assertSame('Berlin, Germany', $this->make('Germany', 'Berlin', null)->locationLabel());
    }

    private function make(?string $country, ?string $locality, ?string $province): Opportunity
    {
        return new Opportunity([
            'country' => $country,
            'target_locality' => $locality,
            'required_province' => $province,
        ]);
    }

    private function listing(string $title, string $country): Opportunity
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        return Opportunity::create([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => $title,
            'description' => 'A fixture listing held in ' . $country . '.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => $country,
            'target_country' => $country,
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDays(5),
            'reviewed_at' => Carbon::now()->subDays(4),
            'reviewed_by' => 'admin@scholarzim.co.zw',
        ]);
    }
}
