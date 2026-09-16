<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\FormOptions;
use App\Support\ZimbabweLocalities;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A town and a province that cannot both be true.
 *
 * Locality stays free text - Zimbabwe has more growth points than any dropdown
 * would carry, and forcing a choice locks out the applicants this platform
 * exists for. But "Midlands" plus "Gwanda" is not an unlisted place, it is a
 * contradiction: Gwanda is in Matabeleland South, and a profile claiming both
 * gets matched against province-restricted awards on a province it does not
 * live in.
 *
 * So a recognised town is checked against the stated province, and an
 * unrecognised one is left alone.
 */
class LocalityProvinceTest extends TestCase
{
    use RefreshDatabase;

    private User $applicant;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->applicant = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function profileFields(array $overrides = []): array
    {
        return array_merge([
            'full_name' => $this->applicant->full_name,
            'education_level' => EducationLevel::A_LEVEL,
            'institution_name' => 'Gweru High School',
        ], $overrides);
    }

    // -------------------------------------------------------- the lookup --

    public function test_the_reported_case_gwanda_is_not_in_the_midlands(): void
    {
        $this->assertSame('Matabeleland South', ZimbabweLocalities::provinceFor('Gwanda'));
        $this->assertFalse(ZimbabweLocalities::agreesWithProvince('Gwanda', 'Midlands'));
        $this->assertTrue(ZimbabweLocalities::agreesWithProvince('Gwanda', 'Matabeleland South'));
    }

    public function test_the_lookup_is_case_and_space_insensitive(): void
    {
        $this->assertSame('Midlands', ZimbabweLocalities::provinceFor('  gWeru '));
        $this->assertSame('Matabeleland North', ZimbabweLocalities::provinceFor('victoria falls'));
    }

    /** An unfamiliar place is not a contradiction - it is simply not in the list. */
    public function test_an_unrecognised_locality_is_never_called_wrong(): void
    {
        $this->assertNull(ZimbabweLocalities::provinceFor('Mudzimundiringe Growth Point'));
        $this->assertNull(ZimbabweLocalities::agreesWithProvince('Mudzimundiringe Growth Point', 'Midlands'));
        $this->assertNull(ZimbabweLocalities::mismatchReason('Mudzimundiringe Growth Point', 'Midlands'));
    }

    public function test_a_blank_on_either_side_is_not_a_contradiction(): void
    {
        $this->assertNull(ZimbabweLocalities::mismatchReason(null, 'Midlands'));
        $this->assertNull(ZimbabweLocalities::mismatchReason('Gwanda', null));
        $this->assertNull(ZimbabweLocalities::mismatchReason('', ''));
    }

    /** Every province in the form's list has at least one locality behind it. */
    public function test_every_province_is_covered(): void
    {
        foreach (FormOptions::ZIMBABWE_PROVINCES as $province) {
            $this->assertNotEmpty(
                ZimbabweLocalities::forProvince($province),
                "$province has no known localities"
            );
        }
    }

    /** No town may be claimed by two provinces, or the check would contradict itself. */
    public function test_no_locality_appears_under_two_provinces(): void
    {
        $seen = [];

        foreach (ZimbabweLocalities::map() as $province => $localities) {
            foreach ($localities as $locality) {
                $key = strtolower($locality);
                $this->assertArrayNotHasKey(
                    $key,
                    $seen,
                    "$locality is listed under both ".($seen[$key] ?? '?')." and $province"
                );
                $seen[$key] = $province;
            }
        }
    }

    // ------------------------------------------------ the applicant form --

    public function test_an_applicant_cannot_save_gwanda_under_the_midlands(): void
    {
        $this->actingAs($this->applicant)
            ->post('/applicant/profile', $this->profileFields([
                'province' => 'Midlands',
                'locality' => 'Gwanda',
            ]))
            ->assertSessionHasErrors('locality');

        $profile = ApplicantProfile::where('user_id', $this->applicant->user_id)->firstOrFail();
        $this->assertNotSame('Gwanda', $profile->locality);
    }

    public function test_the_error_names_the_province_the_town_is_actually_in(): void
    {
        $this->actingAs($this->applicant)
            ->post('/applicant/profile', $this->profileFields([
                'province' => 'Midlands',
                'locality' => 'Gwanda',
            ]))
            ->assertSessionHasErrors([
                'locality' => 'Gwanda is in Matabeleland South, not Midlands. '
                    .'Choose the province it is actually in, or clear the town.',
            ]);
    }

    public function test_a_matching_town_and_province_saves(): void
    {
        $this->actingAs($this->applicant)
            ->post('/applicant/profile', $this->profileFields([
                'province' => 'Midlands',
                'locality' => 'Gweru',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'Gweru',
            ApplicantProfile::where('user_id', $this->applicant->user_id)->value('locality')
        );
    }

    /** The free-text intent survives: an unlisted place is still accepted. */
    public function test_an_unlisted_growth_point_is_still_accepted(): void
    {
        $this->actingAs($this->applicant)
            ->post('/applicant/profile', $this->profileFields([
                'province' => 'Midlands',
                'locality' => 'Mudzimundiringe Growth Point',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'Mudzimundiringe Growth Point',
            ApplicantProfile::where('user_id', $this->applicant->user_id)->value('locality')
        );
    }

    // -------------------------------------------------- the provider form --

    public function test_a_listing_cannot_target_a_town_outside_its_required_province(): void
    {
        $this->actingAs($this->provider)
            ->post('/opportunities/create', [
                'title' => 'Mismatched Locality Award',
                'description' => 'A listing whose province and town disagree.',
                'education_level' => EducationLevel::UNDERGRADUATE,
                'funding_type' => 'Full Scholarship',
                'deadline' => Carbon::today()->addDays(30)->toDateString(),
                'required_province' => 'Midlands',
                'target_locality' => 'Gwanda',
            ])
            ->assertSessionHasErrors('target_locality');

        $this->assertNull(Opportunity::where('title', 'Mismatched Locality Award')->first());
    }

    public function test_a_listing_with_a_matching_town_and_province_is_accepted(): void
    {
        $this->actingAs($this->provider)
            ->post('/opportunities/create', [
                'title' => 'Matching Locality Award',
                'description' => 'A listing whose province and town agree.',
                'education_level' => EducationLevel::UNDERGRADUATE,
                'funding_type' => 'Full Scholarship',
                'deadline' => Carbon::today()->addDays(30)->toDateString(),
                'required_province' => 'Matabeleland South',
                'target_locality' => 'Gwanda',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'Gwanda',
            Opportunity::where('title', 'Matching Locality Award')->value('target_locality')
        );
    }
}
