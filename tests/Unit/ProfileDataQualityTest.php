<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Services\ProfileDataQuality;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsAcademicRecords;
use Tests\TestCase;

/**
 * Warnings about a profile that disagrees with itself. They are warnings, never blocks: the
 * applicant is shown them and decides, and ScholarFit judges them on what they have stated
 * either way.
 */
class ProfileDataQualityTest extends TestCase
{
    use BuildsAcademicRecords;

    private function profile(array $attributes, array $results = []): ApplicantProfile
    {
        return $this->profileWithAcademicResults($results, $attributes);
    }

    /** @return array<int, string> */
    private function codes(ApplicantProfile $profile): array
    {
        return array_column(ProfileDataQuality::warnings($profile), 'code');
    }

    public function test_a_consistent_profile_has_no_warnings(): void
    {
        $profile = $this->profile(
            ['education_level' => EducationLevel::A_LEVEL, 'date_of_birth' => Carbon::today()->subYears(18)->toDateString()],
            [AcademicCatalogue::ZIMSEC_A_LEVEL => ['Mathematics' => 'A']]
        );

        $this->assertSame([], $this->codes($profile));
    }

    public function test_a_level_that_does_not_fit_the_age_is_flagged(): void
    {
        $profile = $this->profile(['education_level' => EducationLevel::PRIMARY, 'date_of_birth' => Carbon::today()->subYears(30)->toDateString()]);

        $this->assertSame([ProfileDataQuality::AGE], $this->codes($profile));
    }

    public function test_a_child_who_has_grown_out_of_primary_is_caught_with_no_edit_at_all(): void
    {
        // Saving validates the age against the level, but nobody re-saves a profile as the years pass.
        $profile = $this->profile(['education_level' => EducationLevel::PRIMARY, 'date_of_birth' => Carbon::today()->subYears(17)->toDateString()]);

        $this->assertContains(ProfileDataQuality::AGE, $this->codes($profile));
    }

    public function test_a_mature_student_is_never_flagged_for_age(): void
    {
        $profile = $this->profile(['education_level' => EducationLevel::UNDERGRADUATE, 'date_of_birth' => Carbon::today()->subYears(48)->toDateString()]);

        $this->assertSame([], $this->codes($profile));
    }

    public function test_results_recorded_above_the_stated_level_are_flagged(): void
    {
        $profile = $this->profile(
            ['education_level' => EducationLevel::O_LEVEL, 'date_of_birth' => Carbon::today()->subYears(17)->toDateString()],
            [AcademicCatalogue::ZIMSEC_A_LEVEL => ['Mathematics' => 'A']]
        );

        $warnings = ProfileDataQuality::warnings($profile);

        $this->assertSame([ProfileDataQuality::RESULTS], array_column($warnings, 'code'));
        $this->assertStringContainsString('A Level', $warnings[0]['message']);
        $this->assertStringContainsString('O Level', $warnings[0]['message']);
    }

    public function test_results_at_or_below_the_level_are_fine(): void
    {
        $profile = $this->profile(
            ['education_level' => EducationLevel::A_LEVEL, 'date_of_birth' => Carbon::today()->subYears(18)->toDateString()],
            [
                AcademicCatalogue::ZIMSEC_O_LEVEL => ['Mathematics' => 'A'],
                AcademicCatalogue::ZIMSEC_A_LEVEL => ['Mathematics' => 'B'],
            ]
        );

        $this->assertSame([], $this->codes($profile));
    }

    public function test_missing_facts_are_not_inconsistencies(): void
    {
        $this->assertSame([], $this->codes($this->profile(['education_level' => null, 'date_of_birth' => null])));
        $this->assertSame([], $this->codes($this->profile(['education_level' => EducationLevel::MASTERS, 'date_of_birth' => null])));
    }

    public function test_every_warning_says_where_to_fix_it(): void
    {
        $profile = $this->profile(['education_level' => EducationLevel::PRIMARY, 'date_of_birth' => Carbon::today()->subYears(30)->toDateString()]);

        foreach (ProfileDataQuality::warnings($profile) as $warning) {
            $this->assertNotEmpty($warning['field'], 'the profile field to jump to');
            $this->assertNotEmpty($warning['message']);
        }
    }

    // --------------------------------------------------- the yearly prompt --

    public function test_a_level_is_due_for_confirmation_after_a_year(): void
    {
        $stale = $this->profile(['education_level' => EducationLevel::A_LEVEL]);
        $stale->education_level_confirmed_at = Carbon::now()->subDays(366);

        $fresh = $this->profile(['education_level' => EducationLevel::A_LEVEL]);
        $fresh->education_level_confirmed_at = Carbon::now()->subDays(100);

        $this->assertTrue(ProfileDataQuality::levelNeedsConfirming($stale));
        $this->assertFalse(ProfileDataQuality::levelNeedsConfirming($fresh));
    }

    public function test_a_profile_never_confirmed_counts_from_when_it_was_created(): void
    {
        $old = $this->profile(['education_level' => EducationLevel::A_LEVEL]);
        $old->created_at = Carbon::now()->subYears(2);

        $new = $this->profile(['education_level' => EducationLevel::A_LEVEL]);
        $new->created_at = Carbon::now()->subDays(20);

        $this->assertTrue(ProfileDataQuality::levelNeedsConfirming($old));
        $this->assertFalse(ProfileDataQuality::levelNeedsConfirming($new), 'a new profile is not asked to confirm what it just said');
    }

    public function test_no_level_means_nothing_to_confirm(): void
    {
        $profile = $this->profile(['education_level' => null]);
        $profile->created_at = Carbon::now()->subYears(3);

        $this->assertFalse(ProfileDataQuality::levelNeedsConfirming($profile));
    }

    public function test_the_interval_comes_from_config(): void
    {
        config(['scholarzim.profile.confirm_level_after_days' => 30]);

        $profile = $this->profile(['education_level' => EducationLevel::A_LEVEL]);
        $profile->education_level_confirmed_at = Carbon::now()->subDays(45);

        $this->assertTrue(ProfileDataQuality::levelNeedsConfirming($profile));
    }
}
