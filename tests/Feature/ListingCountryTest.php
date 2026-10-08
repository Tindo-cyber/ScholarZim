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

    public function test_the_country_list_is_exactly_the_agreed_one(): void
    {
        $this->assertSame([
            'Zimbabwe', 'South Africa', 'Botswana', 'Namibia', 'Zambia', 'Mauritius', 'Kenya',
            'United Kingdom', 'United States', 'Canada', 'Australia', 'China', 'India', 'Russia',
            'Hungary', 'Germany', 'Turkey', 'Malaysia', 'Egypt', 'Japan',
            'Any country', 'Online / distance',
        ], \App\Support\FormOptions::COUNTRIES);
    }

    public function test_a_real_country_search_also_finds_listings_usable_anywhere_and_online(): void
    {
        $this->listing('Qzx Berlin Award', 'Germany');
        $this->listing('Qzx Harare Award', 'Zimbabwe');
        $this->listing('Qzx Anywhere Award', 'Any country');
        $this->listing('Qzx Remote Award', 'Online / distance');

        $this->get('/scholarships?keyword=Qzx&country=Germany')
            ->assertSee('Qzx Berlin Award')
            ->assertSee('Qzx Anywhere Award')
            ->assertSee('Qzx Remote Award')
            ->assertDontSee('Qzx Harare Award');

        $this->get('/scholarships?keyword=Qzx&country=Zimbabwe')
            ->assertSee('Qzx Harare Award')
            ->assertSee('Qzx Anywhere Award')
            ->assertSee('Qzx Remote Award')
            ->assertDontSee('Qzx Berlin Award');
    }

    public function test_choosing_online_shows_only_online_listings(): void
    {
        $this->listing('Berlin Award', 'Germany');
        $this->listing('Anywhere Award', 'Any country');
        $this->listing('Remote Award', 'Online / distance');

        $this->get('/scholarships?country=' . urlencode('Online / distance'))
            ->assertSee('Remote Award')
            ->assertDontSee('Anywhere Award')
            ->assertDontSee('Berlin Award');
    }

    public function test_choosing_any_country_shows_only_those_listings(): void
    {
        $this->listing('Berlin Award', 'Germany');
        $this->listing('Anywhere Award', 'Any country');
        $this->listing('Remote Award', 'Online / distance');

        $this->get('/scholarships?country=' . urlencode('Any country'))
            ->assertSee('Anywhere Award')
            ->assertDontSee('Remote Award')
            ->assertDontSee('Berlin Award');
    }

    public function test_the_flexible_entries_are_selectable_in_the_filter_and_the_form(): void
    {
        $this->get('/scholarships')
            ->assertSee('<option value="Any country"', false)
            ->assertSee('<option value="Online / distance"', false);

        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($provider)->get('/opportunities/create')
            ->assertSee('<option value="Any country"', false)
            ->assertSee('<option value="Online / distance"', false);
    }

    public function test_a_flexible_listing_is_described_without_a_town_or_province(): void
    {
        $this->assertSame('Online / distance', $this->make('Online / distance', 'Gweru', 'Midlands')->locationLabel());
        $this->assertSame('Any country', $this->make('Any country', null, 'Harare')->locationLabel());
    }

    public function test_the_card_and_page_say_online_or_any_country(): void
    {
        $online = $this->listing('Remote Award', 'Online / distance');

        $this->get('/scholarships?country=' . urlencode('Online / distance'))->assertSee('Online / distance');
        $this->get('/scholarships/' . $online->opportunity_id)->assertOk()->assertSee('Online / distance');
    }

    public function test_the_provider_can_publish_a_flexible_listing(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        foreach (['Any country', 'Online / distance'] as $country) {
            $this->flushSession();
            $this->actingAs($provider)->post('/opportunities/create', [
                'title' => 'Flexible ' . $country,
                'description' => 'A flexible-location listing.',
                'education_level' => EducationLevel::MASTERS,
                'country' => $country,
            ])->assertSessionHasNoErrors();

            $this->assertSame($country, Opportunity::where('title', 'Flexible ' . $country)->firstOrFail()->country);
        }
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
