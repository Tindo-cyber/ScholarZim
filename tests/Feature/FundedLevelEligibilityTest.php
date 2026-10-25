<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\RecommendationService;
use App\Services\ScholarFit\DescriptionConflicts;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GivesRequiredDocuments;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The level a scholarship FUNDS is not the level an applicant has FINISHED, and a results document on file is not
 * proof of the qualifications the award is entered with.
 *
 * The regression: "Madrid Scholarship for A level student" was saved with "Level of study" = O Level (the level of
 * the students, not of the award it funds). A Primary pupil is a recognised step toward O Level, nothing contradicted
 * the setting, and the one stated requirement - proof of results - was satisfied by a Grade 7 slip. She was ELIGIBLE
 * for an A-Level award.
 *
 * Kudzai is the seeded Primary pupil (Grade 7 results), Farai the seeded O-Level leaver (O-Level results).
 */
class FundedLevelEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use GivesRequiredDocuments;

    private const MADRID_DESCRIPTION = 'This scholarship is intended for O-Level students seeking an A-Level scholarship.';

    private User $primaryPupil;

    private User $oLevelLeaver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->primaryPupil = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $this->oLevelLeaver = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();

        // Both hold the proof of results the listings below ask for, so what is under test is never a missing file.
        Storage::disk((string) config('filesystems.default', 'local'))->put('profiles/test/slip.pdf', "%PDF-1.4
% test
");
        ApplicantProfile::where('user_id', $this->primaryPupil->user_id)->firstOrFail()->forceFill([
            'grade7_slip_path' => 'profiles/test/slip.pdf', 'grade7_slip_filename' => 'slip.pdf', 'grade7_slip_uploaded_at' => now(),
        ])->save();
        $this->giveRequiredDocuments($this->oLevelLeaver);
    }

    private function listing(array $attributes): Opportunity
    {
        return Opportunity::create($attributes + [
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->firstOrFail()->user_id,
            'provider_name' => 'Madrid Foundation',
            'title' => 'Madrid Scholarship for A level student',
            'description' => self::MADRID_DESCRIPTION,
            'requires_results_certificate' => true,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(),
            'deadline' => now()->addMonths(2),
            'created_at' => now(),
        ]);
    }

    /** The record as it was saved: the students' level, not the award's. */
    private function mislabelled(): Opportunity
    {
        return $this->listing(['education_level' => EducationLevel::O_LEVEL]);
    }

    /** The record as it should be: it funds A-Level, and is entered with O-Level. */
    private function corrected(): Opportunity
    {
        return $this->listing(['education_level' => EducationLevel::A_LEVEL, 'minimum_education_level' => EducationLevel::O_LEVEL]);
    }

    private function oLevelAward(): Opportunity
    {
        return $this->listing([
            'title' => 'Gweru O Level Bursary', 'description' => 'Helps pupils start Form 1.',
            'education_level' => EducationLevel::O_LEVEL,
        ]);
    }

    private function fit(User $user, Opportunity $listing)
    {
        return app(RecommendationService::class)->evaluateOne($user->fresh(), $listing);
    }

    private function recommended(User $user, Opportunity $listing): bool
    {
        return collect(app(RecommendationService::class)->forUser($user->fresh(), 0))
            ->contains(fn ($r) => $r->opportunity->opportunity_id === $listing->opportunity_id);
    }

    private function applies(User $user, Opportunity $listing): bool
    {
        $this->actingAs($user)->post('/apply/' . $listing->opportunity_id . '/quick');
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return Application::where('user_id', $user->user_id)->where('opportunity_id', $listing->opportunity_id)->exists();
    }

    private function outcome(User $user, Opportunity $listing, string $type): ?RequirementOutcome
    {
        foreach ($this->fit($user, $listing)->outcomes as $outcome) {
            if ($outcome->type === $type) {
                return $outcome;
            }
        }

        return null;
    }

    // ------------------------------------------------------- the reported listing --

    public function test_a_primary_pupil_with_a_grade_7_slip_is_not_eligible_for_the_madrid_listing_as_it_was_saved(): void
    {
        $listing = $this->mislabelled();

        $this->assertFalse($this->fit($this->primaryPupil, $listing)->meetsRequirements(), 'a title for A-Level students must not be silenced by a setting of O Level');
        $this->assertFalse($this->recommended($this->primaryPupil, $listing));
    }

    public function test_a_primary_pupil_cannot_submit_to_the_madrid_listing_as_it_was_saved(): void
    {
        $this->assertFalse($this->applies($this->primaryPupil, $this->mislabelled()));
    }

    public function test_a_primary_pupil_is_not_eligible_once_the_listing_is_corrected_either(): void
    {
        $listing = $this->corrected();

        $this->assertFalse($this->fit($this->primaryPupil, $listing)->meetsRequirements());
        $this->assertFalse($this->recommended($this->primaryPupil, $listing));
        $this->assertFalse($this->applies($this->primaryPupil, $listing));
    }

    public function test_the_explanation_names_the_missing_prerequisite_and_does_not_claim_the_slip_proves_it(): void
    {
        $fit = $this->fit($this->primaryPupil, $this->corrected());
        $messages = implode(' | ', $fit->failureMessages());

        $this->assertStringContainsString('A Level', $messages, 'it names the level the award funds');
        $this->assertStringContainsString('O Level', $messages, 'it names the missing prerequisite');
        $this->assertStringContainsString('Grade 7 results slip only shows Primary results', $messages);

        foreach ($fit->outcomes as $outcome) {
            $this->assertFalse(
                $outcome->passed && str_contains($outcome->message, 'slip is on file'),
                'a Grade 7 slip being on file must not be reported as a requirement met'
            );
        }
    }

    public function test_the_stricter_reading_names_the_title_when_the_setting_disagrees_with_it(): void
    {
        $messages = implode(' | ', $this->fit($this->primaryPupil, $this->mislabelled())->failureMessages());

        $this->assertStringContainsString('identified from the scholarship title', $messages);
        $this->assertStringContainsString('A Level', $messages);
    }

    // ----------------------------------------------- who does qualify for A-Level --

    public function test_an_applicant_with_o_level_results_qualifies_for_the_corrected_a_level_scholarship(): void
    {
        $listing = $this->corrected();

        $this->assertTrue($this->fit($this->oLevelLeaver, $listing)->meetsRequirements());
        $this->assertTrue($this->recommended($this->oLevelLeaver, $listing));
        $this->assertTrue($this->applies($this->oLevelLeaver, $listing));
    }

    public function test_a_grade_7_slip_does_not_stand_in_for_o_level_results(): void
    {
        $listing = $this->corrected();
        $outcome = $this->outcome($this->primaryPupil, $listing, RequirementOutcome::TYPE_CERTIFICATE);

        $this->assertNotNull($outcome);
        $this->assertFalse($outcome->passed);
        $this->assertFalse($outcome->needsInformation, 'this is not a document they can still upload');

        // And the document slot itself: an O-Level leaver holding only a slip has not shown O-Level results.
        $profile = ApplicantProfile::where('user_id', $this->oLevelLeaver->user_id)->firstOrFail();
        $profile->forceFill(['results_certificate_path' => null, 'results_certificate_filename' => null, 'grade7_slip_path' => 'p/slip.pdf'])->save();

        $this->assertFalse($profile->fresh()->hasRequiredAcademicEvidence());
        $this->assertFalse($this->fit($this->oLevelLeaver, $listing)->meetsRequirements());
    }

    // ---------------------------------------- progression that is still legitimate --

    public function test_a_primary_pupil_still_qualifies_for_a_genuine_o_level_scholarship(): void
    {
        $listing = $this->oLevelAward();

        $this->assertTrue($this->fit($this->primaryPupil, $listing)->meetsRequirements());
        $this->assertTrue($this->recommended($this->primaryPupil, $listing));
        $this->assertTrue($this->applies($this->primaryPupil, $listing));
    }

    public function test_a_title_naming_the_same_level_as_the_setting_changes_nothing(): void
    {
        $listing = $this->listing(['title' => 'Madrid Scholarship for O level student', 'education_level' => EducationLevel::O_LEVEL]);

        $this->assertSame([], DescriptionConflicts::contradictingTitleConditions($listing));
        $this->assertTrue($this->fit($this->primaryPupil, $listing)->meetsRequirements());
    }

    // ------------------------------------- funded level vs. the level finished --

    public function test_the_funded_level_is_not_the_applicants_finished_level(): void
    {
        // An O-Level leaver has FINISHED O Level: an award that funds O Level is behind them,
        $this->assertFalse($this->fit($this->oLevelLeaver, $this->oLevelAward())->meetsRequirements());
        // and the award that funds A Level is the next stage.
        $this->assertTrue($this->fit($this->oLevelLeaver, $this->corrected())->meetsRequirements());
    }

    // ---------------------------------------------- detecting the contradiction --

    public function test_an_explicit_title_level_that_differs_from_the_setting_is_a_conflict(): void
    {
        $conflicts = DescriptionConflicts::detect($this->mislabelled());

        $this->assertCount(1, $conflicts);
        $this->assertSame('education_level', $conflicts[0]['field']);
        $this->assertStringContainsString('A Level', $conflicts[0]['message']);
    }

    public function test_a_corrected_listing_has_no_conflict(): void
    {
        $this->assertSame([], DescriptionConflicts::detect($this->corrected()));
    }

    public function test_a_generic_secondary_phrase_in_the_title_does_not_conflict_with_a_level_setting(): void
    {
        $listing = $this->listing(['title' => 'High School Scholarship', 'education_level' => EducationLevel::A_LEVEL]);

        $this->assertSame([], DescriptionConflicts::contradictingTitleConditions($listing));
    }

    // ----------------------------------- what a title level means (entry vs. funded) --

    public function test_a_title_naming_the_entry_qualification_is_not_a_contradiction(): void
    {
        $listing = $this->listing(['title' => 'Degree Award open to O-Level holders', 'education_level' => EducationLevel::UNDERGRADUATE, 'description' => 'Covers fees.']);

        // (The older advisory warning about the wording is unchanged; what matters is that eligibility reads no contradiction.)
        $this->assertSame([], DescriptionConflicts::contradictingTitleConditions($listing));
    }

    public function test_a_title_with_alternative_levels_agrees_with_any_of_them(): void
    {
        $listing = $this->listing(['title' => "Undergraduate and Master's Scholarship", 'education_level' => EducationLevel::UNDERGRADUATE]);

        $this->assertSame([], DescriptionConflicts::contradictingTitleConditions($listing));
    }

    public function test_a_title_for_a_later_stage_than_the_setting_is_a_contradiction(): void
    {
        $this->assertNotSame([], DescriptionConflicts::contradictingTitleConditions($this->mislabelled()));
    }

    // --------------------------------------------------------- correcting the record --

    public function test_the_correction_command_is_a_dry_run_until_told_otherwise(): void
    {
        $listing = $this->mislabelled();

        $this->artisan('scholarzim:fix-contradicted-listing-levels')->assertSuccessful();

        $this->assertSame(EducationLevel::O_LEVEL, $listing->fresh()->education_level);
    }

    public function test_the_correction_command_fixes_the_level_and_records_the_entry_level_the_description_states(): void
    {
        $listing = $this->mislabelled();
        $fine = $this->oLevelAward();

        $this->artisan('scholarzim:fix-contradicted-listing-levels', ['--apply' => true])->assertSuccessful();

        $this->assertSame(EducationLevel::A_LEVEL, $listing->fresh()->education_level);
        $this->assertSame(EducationLevel::O_LEVEL, $listing->fresh()->minimum_education_level);
        $this->assertSame(EducationLevel::O_LEVEL, $fine->fresh()->education_level, 'a consistent listing is left alone');
        $this->assertDatabaseHas('audit_log', ['action' => 'UPDATE_OPPORTUNITY', 'entity_id' => $listing->opportunity_id]);

        $this->assertFalse($this->fit($this->primaryPupil, $listing->fresh())->meetsRequirements());
        $this->assertTrue($this->fit($this->oLevelLeaver, $listing->fresh())->meetsRequirements());
    }
}
