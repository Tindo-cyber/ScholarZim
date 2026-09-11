<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\ScholarFitEngine;
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
     * The whole point of the column: a listing aimed at rural students scores
     * higher for one than for an otherwise identical urban applicant.
     */
    public function test_a_rural_targeted_award_ranks_a_rural_student_above_an_urban_one(): void
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();
        $opportunity->update([
            'target_settlement_type' => SettlementType::RURAL,
            'deadline' => Carbon::today()->addDays(20),
        ]);

        $engine = app(ScholarFitEngine::class);
        $profile = $this->student->applicantProfile;

        $profile->update(['settlement_type' => SettlementType::RURAL]);
        $rural = $engine->evaluate($profile->fresh(), $opportunity)->matchScore;

        $profile->update(['settlement_type' => SettlementType::URBAN]);
        $urban = $engine->evaluate($profile->fresh(), $opportunity)->matchScore;

        $this->assertGreaterThan(
            $urban,
            $rural,
            'a listing that targets rural applicants must actually prefer one'
        );
    }

    /** An unstated settlement type is unknown, so it neither helps nor blocks. */
    public function test_not_stating_a_settlement_type_is_not_treated_as_urban(): void
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();
        $opportunity->update(['target_settlement_type' => SettlementType::RURAL]);

        $engine = app(ScholarFitEngine::class);
        $profile = $this->student->applicantProfile;

        $profile->update(['settlement_type' => null]);

        $scored = $engine->evaluate($profile->fresh(), $opportunity);

        $this->assertTrue($scored->meetsRequirements(), 'a blank settlement type must never disqualify');
        $this->assertGreaterThan(0, $scored->breakdown->dimension('location')->points());
    }

    /** The minimum a valid profile POST needs, so each test states only its point. */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'full_name' => $this->student->full_name,
            'education_level' => EducationLevel::UNDERGRADUATE,
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

    public function test_an_age_below_seven_is_rejected(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form([
                'date_of_birth' => Carbon::today()->subYears(6)->toDateString(),
            ]))
            ->assertSessionHasErrors('date_of_birth');
    }

    public function test_exactly_seven_years_old_is_accepted(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form([
                'date_of_birth' => Carbon::today()->subYears(7)->toDateString(),
            ]))
            ->assertRedirect();
    }

    public function test_a_normal_adult_age_is_accepted(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form([
                'date_of_birth' => Carbon::today()->subYears(21)->toDateString(),
            ]))
            ->assertRedirect();
    }
}
