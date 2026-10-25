<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * An organisation registered outside Zimbabwe funds Certificate, Diploma, Degree and above - not school levels, and not
 * "any level". One registered in Zimbabwe, or whose country was never recorded, is not limited. The form offers only the
 * levels allowed, and the server refuses the rest.
 */
class InternationalProviderLevelsTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function registeredIn(?string $code): void
    {
        ProviderProfile::where('user_id', $this->provider->user_id)->update(['registration_country' => $code]);
        $this->provider = $this->provider->fresh('providerProfile');
    }

    private function create(array $overrides = [])
    {
        return $this->actingAs($this->provider)->post('/opportunities/create', array_merge([
            'title' => 'Level Test Award ' . uniqid(),
            'description' => 'A listing used to try which levels an organisation may fund.',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'open_to' => 'all',
            'institutions_open_to' => 'any',
            'deadline' => Carbon::today()->addDays(45)->toDateString(),
        ], $overrides));
    }

    public static function schoolLevels(): array
    {
        return ['Form 1' => [EducationLevel::FORM_1], 'O-Level' => [EducationLevel::O_LEVEL], 'A-Level' => [EducationLevel::A_LEVEL]];
    }

    public static function allowedLevels(): array
    {
        return array_map(fn ($level) => [$level], EducationLevel::ABROAD_PROVIDER_LEVELS);
    }

    /** @dataProvider schoolLevels */
    #[\PHPUnit\Framework\Attributes\DataProvider('schoolLevels')]
    public function test_an_organisation_registered_abroad_cannot_fund_a_school_level(string $level): void
    {
        $this->registeredIn('GB');

        $this->create(['education_level' => $level])->assertSessionHasErrors('education_level');

        $this->assertSame(0, Opportunity::where('provider_user_id', $this->provider->user_id)->where('title', 'like', 'Level Test Award%')->count());
    }

    public function test_it_cannot_leave_the_level_open_either(): void
    {
        $this->registeredIn('GB');

        $this->create(['education_level' => ''])->assertSessionHasErrors('education_level');
    }

    /** @dataProvider allowedLevels */
    #[\PHPUnit\Framework\Attributes\DataProvider('allowedLevels')]
    public function test_it_can_fund_certificate_diploma_degree_and_above(string $level): void
    {
        $this->registeredIn('GB');

        $this->create(['education_level' => $level])->assertSessionDoesntHaveErrors('education_level');
    }

    public function test_a_master_listing_from_an_organisation_abroad_is_created(): void
    {
        $this->registeredIn('ZA');

        $this->create(['education_level' => EducationLevel::MASTERS, 'title' => 'Masters From Abroad'])->assertSessionHasNoErrors();

        $this->assertSame(1, Opportunity::where('title', 'Masters From Abroad')->count());
    }

    public function test_an_organisation_registered_in_zimbabwe_is_not_limited(): void
    {
        $this->registeredIn('ZW');

        $this->create(['education_level' => EducationLevel::A_LEVEL])->assertSessionDoesntHaveErrors('education_level');
        $this->create(['education_level' => ''])->assertSessionDoesntHaveErrors('education_level');
    }

    public function test_an_account_with_no_recorded_country_is_not_limited(): void
    {
        $this->registeredIn(null);

        $this->create(['education_level' => EducationLevel::A_LEVEL])->assertSessionDoesntHaveErrors('education_level');
    }

    public function test_the_form_offers_only_the_allowed_levels_to_an_organisation_abroad(): void
    {
        $this->registeredIn('GB');

        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        // The "Level of study" list only: "Minimum qualifying level" is what an applicant must already hold, and is not limited.
        preg_match('/<select[^>]*name="education_level".*?<\/select>/s', $html, $m);
        $select = $m[0] ?? '';
        $this->assertNotSame('', $select, 'the level of study list is on the page');

        foreach (EducationLevel::ABROAD_PROVIDER_LEVELS as $level) {
            $this->assertStringContainsString('value="' . $level . '"', $select, $level);
        }

        foreach ([EducationLevel::FORM_1, EducationLevel::O_LEVEL, EducationLevel::A_LEVEL] as $level) {
            $this->assertStringNotContainsString('value="' . $level . '"', $select, $level . ' is not offered');
        }

        $this->assertStringNotContainsString('Any level', $select);
        $this->assertStringContainsString('registered outside Zimbabwe can fund Certificate, Diploma, Degree', $html);
    }

    public function test_the_form_offers_every_level_to_an_organisation_in_zimbabwe(): void
    {
        $this->registeredIn('ZW');

        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        foreach ([EducationLevel::FORM_1, EducationLevel::O_LEVEL, EducationLevel::A_LEVEL, EducationLevel::MASTERS] as $level) {
            $this->assertStringContainsString('<option value="' . $level . '"', $html, $level);
        }

        $this->assertStringNotContainsString('registered outside Zimbabwe can fund', $html);
    }

    public function test_an_edit_cannot_move_a_listing_to_a_school_level(): void
    {
        $this->registeredIn('GB');
        $listing = Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => $this->provider->full_name,
            'title' => 'Existing Masters Award', 'description' => 'An existing listing from an organisation abroad.',
            'education_level' => EducationLevel::MASTERS, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'open_to_all_programmes' => true, 'any_institution' => true,
            'deadline' => Carbon::today()->addDays(30), 'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ]);

        $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, [
            'title' => 'Existing Masters Award', 'description' => 'An existing listing from an organisation abroad.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'education_level' => EducationLevel::A_LEVEL,
            'open_to' => 'all', 'institutions_open_to' => 'any', 'deadline' => Carbon::today()->addDays(30)->toDateString(),
        ])->assertSessionHasErrors('education_level');

        $this->assertSame(EducationLevel::MASTERS, $listing->fresh()->education_level);
    }
}
