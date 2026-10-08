<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\Taxonomy\SettlementType;
use App\Support\EducationLevel;
use App\Support\FormOptions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Location as three separate things, reached the way a student actually reaches
 * it - through the profile form: province, a specific locality (free text, e.g.
 * "Gutu"), and settlement type (rural or urban, a closed vocabulary).
 *
 * This exists because of how v1's rural rule failed. The code read
 * `province === 'Rural'`, which looked like a working feature in review and
 * could never fire, because "Rural" was not among the ten provinces the dropdown
 * offered. A unit test on the matcher alone would not have caught that; only
 * going through the form does.
 */
class ScholarFitLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    /** The form accepts a free-text locality and a settlement type, and they reach the profile. */
    public function test_a_student_can_state_their_locality_and_whether_they_are_rural(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form([
                'province' => 'Masvingo',
                'locality' => 'Gutu',
                'settlement_type' => SettlementType::RURAL,
            ]))
            ->assertRedirect();

        $profile = $this->student->fresh()->applicantProfile;

        $this->assertSame('Masvingo', $profile->province);
        $this->assertSame('Gutu', $profile->locality);
        $this->assertSame(SettlementType::RURAL, $profile->settlement_type);
    }

    /** Settlement type is its own closed vocabulary, not free text and not a province. */
    public function test_a_province_name_is_not_accepted_as_a_settlement_type(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form(['settlement_type' => 'Masvingo']))
            ->assertSessionHasErrors('settlement_type');
    }

    /** Locality, unlike settlement type, is free text - a province-shaped value is simply accepted as a place name. */
    public function test_locality_accepts_free_text(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form(['locality' => 'Chegutu']))
            ->assertSessionDoesntHaveErrors('locality');
    }

    public function test_rural_is_not_offered_as_a_province(): void
    {
        $this->assertNotContains('Rural', FormOptions::ZIMBABWE_PROVINCES);
    }

    /**
     * Settlement type is a rule the provider can set, and the listing form says it
     * disqualifies. It used to be saved and read by nothing, which is what this
     * test once recorded as "never disqualifies". It now does.
     */
    public function test_a_rural_targeted_award_does_not_suit_an_urban_applicant(): void
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Bursary')->firstOrFail();
        $opportunity->update([
            'target_settlement_type' => SettlementType::RURAL,
            'deadline' => Carbon::today()->addDays(20),
        ]);

        $this->student->applicantProfile->update(['settlement_type' => SettlementType::URBAN]);

        $fit = app(\App\Services\RecommendationService::class)->evaluateOne($this->student->fresh(), $opportunity);

        $this->assertTrue($fit->isIneligible());
        $this->assertStringContainsString('Rural', implode(' ', $fit->failureMessages()));
    }

    public function test_a_matching_settlement_type_is_met(): void
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Bursary')->firstOrFail();
        $opportunity->update(['target_settlement_type' => SettlementType::RURAL]);

        $this->student->applicantProfile->update(['settlement_type' => SettlementType::RURAL]);

        $fit = app(\App\Services\RecommendationService::class)->evaluateOne($this->student->fresh(), $opportunity);

        $this->assertTrue($fit->meetsRequirements());
    }

    /**
     * An unstated settlement type is not a failure - and, now that it is checked, not
     * a match either: nobody can say, so the student is asked.
     */
    public function test_not_stating_a_settlement_type_is_a_question_not_a_failure_or_a_match(): void
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Bursary')->firstOrFail();
        $opportunity->update(['target_settlement_type' => SettlementType::RURAL]);

        $this->student->applicantProfile->update(['settlement_type' => null]);

        $fit = app(\App\Services\RecommendationService::class)->evaluateOne($this->student->fresh(), $opportunity);

        $this->assertFalse($fit->isIneligible(), 'a blank settlement type must never disqualify');
        $this->assertTrue($fit->needsInformation());
        $this->assertFalse($fit->meetsRequirements());
        $this->assertSame(['settlement type'], $fit->missingFields());
    }
    /** The minimum a valid profile POST needs, so each test states only its point. */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'full_name' => $this->student->full_name,
            'education_level' => EducationLevel::UNDERGRADUATE,
            'gender' => 'male',
            'field_of_study' => 'Computer Science & IT',
        ], $overrides);
    }

    // --------------------------------------------------------------- age rules --

    public function test_a_future_date_of_birth_is_rejected(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form([
                'date_of_birth' => Carbon::tomorrow()->toDateString(),
            ]))
            ->assertSessionHasErrors('date_of_birth');
    }

    /**
     * Below the platform-wide floor, whatever the stated education level -
     * O-Level rather than the default Undergraduate, so this exercises only
     * the age-12 floor and not the separate tier/age consistency check
     * (Undergraduate alone requires 14+, which would mask what this asserts).
     */
    public function test_an_age_below_twelve_is_rejected(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form([
                'education_level' => EducationLevel::O_LEVEL,
                'date_of_birth' => Carbon::today()->subYears(11)->toDateString(),
            ]))
            ->assertSessionHasErrors('date_of_birth');
    }

    public function test_exactly_twelve_years_old_is_accepted(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form([
                'education_level' => EducationLevel::O_LEVEL,
                'date_of_birth' => Carbon::today()->subYears(12)->toDateString(),
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_normal_adult_age_is_accepted(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form([
                'date_of_birth' => Carbon::today()->subYears(21)->toDateString(),
            ]))
            ->assertSessionHasNoErrors();
    }
}
