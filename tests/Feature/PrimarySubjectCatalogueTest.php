<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\User;
use App\Services\RecommendationService;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Zimbabwe Primary subject catalogue, corrected to the five approved
 * learning-area categories: Mathematics, Physical Education, Science and
 * Technology, Social Science, and Languages. See
 * AcademicCatalogue::subjects() and the migration that retired what this
 * replaced.
 */
class PrimarySubjectCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function primary(): AcademicQualification
    {
        return AcademicQualification::findByKey(AcademicCatalogue::ZIMBABWE_PRIMARY);
    }

    // ---------------------------------------------------- the five categories --

    public function test_primary_exposes_exactly_the_approved_learning_areas(): void
    {
        $names = $this->primary()->activeSubjects->pluck('name')->sort()->values()->all();

        $this->assertSame([
            'English', 'Mathematics', 'Ndebele', 'Physical Education',
            'Science and Technology', 'Shona', 'Social Science',
        ], $names);
    }

    public function test_physical_education_is_available(): void
    {
        $this->assertTrue($this->primary()->activeSubjects->pluck('name')->contains('Physical Education'));
    }

    public function test_science_and_technology_is_available(): void
    {
        $this->assertTrue($this->primary()->activeSubjects->pluck('name')->contains('Science and Technology'));
    }

    public function test_social_science_is_available(): void
    {
        $this->assertTrue($this->primary()->activeSubjects->pluck('name')->contains('Social Science'));
    }

    public function test_mathematics_is_available(): void
    {
        $this->assertTrue($this->primary()->activeSubjects->pluck('name')->contains('Mathematics'));
    }

    /** Languages is not one subject - a Primary pupil sits more than one - so this checks all three survive as separate subjects. */
    public function test_languages_are_available_as_separate_subjects(): void
    {
        $names = $this->primary()->activeSubjects->pluck('name');

        $this->assertTrue($names->contains('English'));
        $this->assertTrue($names->contains('Shona'));
        $this->assertTrue($names->contains('Ndebele'));
    }

    // ------------------------------------------------------- retired subjects --

    public function test_the_previous_primary_subjects_are_no_longer_selectable(): void
    {
        $names = $this->primary()->activeSubjects->pluck('name');

        foreach ($this->retiredSubjectNames() as $retired) {
            $this->assertFalse($names->contains($retired), "$retired must not be offered any more");
        }
    }

    /**
     * Retired, not deleted - but a fresh test database never had the old
     * subject in the first place, since migrations run against the current
     * (already-corrected) catalogue. Simulated here as the one scenario the
     * migration actually exists for: a database where the old catalogue
     * already ran, so the row is already on file before this migration
     * touches it.
     */
    public function test_a_retired_primary_subject_is_deactivated_not_deleted(): void
    {
        $qualificationId = $this->primary()->id;

        $preExisting = AcademicSubject::create([
            'qualification_id' => $qualificationId,
            'name' => 'Agriculture',
            'is_active' => true,
            'ordering' => 99,
        ]);

        $migration = require database_path('migrations/2025_01_01_000006_correct_primary_subject_catalogue.php');
        $migration->up();

        $subject = $preExisting->fresh();

        $this->assertNotNull($subject, 'a retired subject must not be deleted');
        $this->assertFalse((bool) $subject->is_active);
    }

    public function test_an_unsupported_primary_subject_cannot_be_added_to_an_academic_record(): void
    {
        $pupil = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $pupil->user_id)->firstOrFail();

        $retired = $this->inactiveSubject();

        $this->actingAs($pupil)
            ->post('/applicant/profile', [
                'full_name' => $pupil->full_name,
                'education_level' => EducationLevel::PRIMARY,
                'province' => $profile->province,
                'guardian_name' => 'Grace Marufu',
                'guardian_phone' => '0773111001',
                'guardian_relationship' => 'Mother',
                'academic_results_submitted' => '1',
                'academic_subject_results' => [[
                    'qualification_id' => $this->primary()->id,
                    'subject_id' => $retired->id,
                    'result' => '1',
                ]],
            ])
            ->assertSessionHasErrors('academic_subject_results.0.subject_id');
    }

    /** A manually crafted request cannot bypass the dropdown - the server checks is_active itself. */
    public function test_server_side_validation_rejects_an_inactive_subject_regardless_of_the_form(): void
    {
        $pupil = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();

        $retired = $this->inactiveSubject();

        $this->actingAs($pupil)
            ->post('/applicant/profile', [
                'full_name' => $pupil->full_name,
                'education_level' => EducationLevel::PRIMARY,
                'province' => 'Mashonaland West',
                'guardian_name' => 'Grace Marufu',
                'guardian_phone' => '0773111001',
                'guardian_relationship' => 'Mother',
                'academic_results_submitted' => '1',
                'academic_subject_results' => [[
                    'qualification_id' => $this->primary()->id,
                    'subject_id' => $retired->id,
                    'result' => '1',
                ]],
            ])
            ->assertSessionHasErrors('academic_subject_results.0.subject_id');
    }

    // ------------------------------------------------- other catalogues untouched --

    public function test_the_o_level_subject_catalogue_is_unaffected(): void
    {
        $oLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_O_LEVEL);
        $names = $oLevel->activeSubjects->pluck('name');

        $this->assertTrue($names->contains('Combined Science'));
        $this->assertTrue($names->contains('Physics'));
        $this->assertTrue($names->contains('Mathematics'));
        $this->assertGreaterThan(20, $names->count(), 'the full O-Level list must still be intact');
    }

    public function test_the_a_level_subject_catalogue_is_unaffected(): void
    {
        $aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $names = $aLevel->activeSubjects->pluck('name');

        $this->assertTrue($names->contains('Mathematics'));
        $this->assertTrue($names->contains('Physics'));
        $this->assertTrue($names->contains('Chemistry'));
    }

    public function test_cambridge_subject_catalogues_are_unaffected(): void
    {
        $igcse = AcademicQualification::findByKey(AcademicCatalogue::CAMBRIDGE_IGCSE);
        $names = $igcse->activeSubjects->pluck('name');

        $this->assertTrue($names->contains('Biology'));
        $this->assertGreaterThan(5, $names->count());
    }

    // ------------------------------------------------------------ integration --

    /** The seeded Primary pupil's existing results, all under approved subjects, still work end to end. */
    public function test_existing_valid_primary_academic_results_still_work(): void
    {
        $pupil = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $pupil->user_id)->firstOrFail();

        $profile->loadMissing('academicResults.subject');

        $this->assertNotEmpty($profile->academicResults);

        foreach ($profile->academicResults as $result) {
            $this->assertTrue(
                (bool) $result->subject->is_active,
                $result->subject->name . ' must still be an active, approved subject'
            );
        }
    }

    /** ScholarFit reads the same catalogue - a Primary result under an approved subject is still considered. */
    public function test_scholarfit_reads_valid_primary_academic_results(): void
    {
        $pupil = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();

        $fit = app(RecommendationService::class)->scoreOne(
            $pupil,
            \App\Models\Opportunity::where('title', 'Chinhoyi Form 1 Transition Bursary')->firstOrFail()
        );

        $this->assertNotNull($fit);
        $this->assertTrue($fit->meetsRequirements());
    }

    /** A subject that exists but is no longer offered - independent of which real subject was retired when. */
    private function inactiveSubject(): AcademicSubject
    {
        return AcademicSubject::create([
            'qualification_id' => $this->primary()->id,
            'name' => 'Retired Test Subject',
            'is_active' => false,
            'ordering' => 99,
        ]);
    }

    /** @return array<int, string> */
    private function retiredSubjectNames(): array
    {
        return [
            'General Paper',
            'Agriculture',
            'Heritage-Social Studies',
            'Physical Education, Arts and Sport',
            'Information and Communication Technology',
            'Religious and Moral Education',
        ];
    }
}
