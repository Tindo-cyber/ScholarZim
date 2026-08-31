<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\ScholarFitEngine;
use App\Services\ScholarFit\Taxonomy\Locality;
use App\Support\FormOptions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Location as four separate things, reached the way a student actually reaches
 * it - through the profile form.
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

    /** The form accepts district and locality, and they reach the profile. */
    public function test_a_student_can_state_their_district_and_whether_they_are_rural(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form([
                'province' => 'Masvingo',
                'district' => 'Gutu',
                'locality' => Locality::RURAL,
            ]))
            ->assertRedirect();

        $profile = $this->student->fresh()->applicantProfile;

        $this->assertSame('Masvingo', $profile->province);
        $this->assertSame('Gutu', $profile->district);
        $this->assertSame(Locality::RURAL, $profile->locality);
    }

    /** Locality is its own vocabulary, not free text and not a province. */
    public function test_a_province_name_is_not_accepted_as_a_locality(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->form(['locality' => 'Masvingo']))
            ->assertSessionHasErrors('locality');
    }

    public function test_rural_is_not_offered_as_a_province(): void
    {
        $this->assertNotContains('Rural', FormOptions::ZIMBABWE_PROVINCES);
    }

    /**
     * The whole point of the column: a listing aimed at rural students scores
     * one higher than an otherwise identical urban applicant.
     */
    public function test_a_rural_targeted_award_ranks_a_rural_student_above_an_urban_one(): void
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();
        $opportunity->update([
            'target_locality' => Locality::RURAL,
            'deadline' => Carbon::today()->addDays(20),
        ]);

        $engine = app(ScholarFitEngine::class);
        $profile = $this->student->applicantProfile;

        $profile->update(['country' => 'Zimbabwe', 'locality' => Locality::RURAL]);
        $rural = $engine->evaluate($profile->fresh(), $opportunity)->matchScore;

        $profile->update(['locality' => Locality::URBAN]);
        $urban = $engine->evaluate($profile->fresh(), $opportunity)->matchScore;

        $this->assertGreaterThan(
            $urban,
            $rural,
            'a listing that targets rural applicants must actually prefer one'
        );
    }

    /** An unstated locality is unknown, so it neither helps nor blocks. */
    public function test_not_stating_a_locality_is_not_treated_as_urban(): void
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();
        $opportunity->update(['target_locality' => Locality::RURAL]);

        $engine = app(ScholarFitEngine::class);
        $profile = $this->student->applicantProfile;

        $profile->update(['country' => 'Zimbabwe', 'locality' => null]);

        $scored = $engine->evaluate($profile->fresh(), $opportunity);

        $this->assertTrue($scored->meetsRequirements(), 'a blank locality must never disqualify');
        $this->assertGreaterThan(0, $scored->breakdown->dimension('location')->points());
    }

    /** The minimum a valid profile POST needs, so each test states only its point. */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'full_name' => $this->student->full_name,
            'education_level' => 'Undergraduate',
            'field_of_study' => 'Computer Science & IT',
            'country' => 'Zimbabwe',
        ], $overrides);
    }
}
