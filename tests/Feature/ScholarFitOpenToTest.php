<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\ApplicantTargetField;
use App\Models\Field;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ScholarFit with "Who is it open to?" answered, as agreed on 13 Oct 2026.
 *
 *  1. A school-leaver's chosen programmes say which LEVEL they want: one who chose only diplomas is not
 *     matched to a degree award, even in the same field, even one open to all programmes.
 *  2. An award to study abroad is open to fields, and a student's own programme is matched through its field.
 *  3. An enrolled student or graduate can name up to three fields to study next, matched as well.
 *  4. "All programmes", chosen on purpose, outranks anything read out of the listing's words.
 *  5. "Other conditions" are shown as "Also check yourself" and never change the verdict.
 */
class ScholarFitOpenToTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->profile()->forceFill(['field_of_study' => null, 'degree_classification' => null])->save();
        ApplicantProgramme::where('profile_id', $this->profile()->profile_id)->delete();
    }

    // ----------------------------------------------------------------- helpers --

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function field(string $code): Field
    {
        return Field::where('code', $code)->firstOrFail();
    }

    /** An A-Level (or O-Level) school-leaver who hopes to study these. */
    private function leaver(array $programmes, string $level = EducationLevel::A_LEVEL): void
    {
        $this->profile()->forceFill(['education_level' => $level])->save();

        foreach ($programmes as $name) {
            ApplicantProgramme::create([
                'profile_id' => $this->profile()->profile_id,
                'programme_id' => Programme::where('name', $name)->firstOrFail()->id,
                'kind' => ApplicantProgramme::INTENDED,
            ]);
        }
    }

    /** A graduate (or enrolled student) on this programme, optionally wanting these fields next. */
    private function graduate(string $programme, array $targetFieldCodes = [], string $level = EducationLevel::UNDERGRADUATE): void
    {
        $this->profile()->forceFill(['education_level' => $level])->save();

        ApplicantProgramme::create([
            'profile_id' => $this->profile()->profile_id,
            'programme_id' => Programme::where('name', $programme)->firstOrFail()->id,
            'kind' => ApplicantProgramme::CURRENT,
        ]);

        foreach ($targetFieldCodes as $code) {
            ApplicantTargetField::create(['profile_id' => $this->profile()->profile_id, 'field_id' => $this->field($code)->id]);
        }
    }

    private function listing(array $attributes = [], array $fieldCodes = []): Opportunity
    {
        $listing = Opportunity::create($attributes + [
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->value('user_id'),
            'provider_name' => 'Open To Provider',
            'title' => 'An Open To Bursary',
            'description' => 'A listing used to exercise the open-to rules.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'open_to_all_programmes' => $fieldCodes === [],
            'any_institution' => true,
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now(),
        ]);

        foreach ($fieldCodes as $code) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'field_id' => $this->field($code)->id]);
        }

        return $listing->fresh();
    }

    /** A Masters award to study in Germany, open to ICT. */
    private function abroad(array $attributes = []): Opportunity
    {
        return $this->listing($attributes + [
            'education_level' => EducationLevel::MASTERS,
            'country' => 'Germany', 'target_country' => 'Germany',
            'any_institution' => null,
            'host_institutions' => 'A partner university in Germany',
        ], ['061']);
    }

    private function fit(Opportunity $listing): EligibilityResult
    {
        $profile = $this->profile()->fresh();

        return new EligibilityResult(
            $listing,
            app(EligibilityEvaluator::class)->evaluate($profile, $listing, AcademicRecord::fromProfile($profile)),
            true
        );
    }

    private function messages(EligibilityResult $fit): string
    {
        return implode(' | ', array_map(fn (RequirementOutcome $o) => $o->message, $fit->outcomes));
    }

    private function programmeOutcome(EligibilityResult $fit): ?RequirementOutcome
    {
        foreach ($fit->outcomes as $outcome) {
            if ($outcome->type === RequirementOutcome::TYPE_PROGRAMME_SCOPE) {
                return $outcome;
            }
        }

        return null;
    }

    // ------------------------------------------- 1. the level a leaver chose --

    public function test_a_leaver_who_chose_only_diplomas_is_not_matched_to_a_degree_in_the_same_field(): void
    {
        $this->leaver(['Diploma in Information Technology']);

        $fit = $this->fit($this->listing([], ['061']));

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('funds Undergraduate study, but the programmes you chose are at Diploma level', $this->messages($fit));
        $this->assertCount(1, $fit->failureMessages(), 'the level, once - not the level and then the programme again');
    }

    public function test_the_same_leaver_is_matched_to_a_diploma_award_in_that_field(): void
    {
        $this->leaver(['Diploma in Information Technology']);

        $fit = $this->fit($this->listing(['education_level' => EducationLevel::DIPLOMA], ['061']));

        $this->assertTrue($fit->meetsRequirements(), $this->messages($fit));
    }

    public function test_the_level_rule_holds_even_for_an_award_open_to_all_programmes(): void
    {
        $this->leaver(['Diploma in Information Technology']);

        $this->assertFalse($this->fit($this->listing())->meetsRequirements());
    }

    public function test_a_leaver_with_programmes_at_two_levels_is_matched_on_the_one_at_the_awards_level(): void
    {
        $this->leaver(['Diploma in Information Technology', 'BSc Computer Science']);

        $fit = $this->fit($this->listing([], ['061']));

        $this->assertTrue($fit->meetsRequirements(), $this->messages($fit));
        $this->assertStringContainsString('BSc Computer Science', $this->programmeOutcome($fit)->message);
    }

    public function test_only_programmes_at_the_awards_level_count_towards_its_field(): void
    {
        // The diploma is in ICT, the degree is not: an ICT degree award is not theirs.
        $this->leaver(['Diploma in Information Technology', 'BCom Accounting']);

        $fit = $this->fit($this->listing([], ['061']));

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('BCom Accounting', $this->programmeOutcome($fit)->message);
    }

    public function test_a_leaver_who_has_chosen_nothing_is_asked_not_refused(): void
    {
        $this->leaver([]);

        $fit = $this->fit($this->listing([], ['061']));

        $this->assertFalse($fit->meetsRequirements());
        $this->assertTrue($fit->needsInformation());
        $this->assertSame([], $fit->failureMessages());
    }

    // ------------------------------------------------------- 2. study abroad --

    public function test_a_computer_science_graduate_matches_an_ict_masters_abroad_through_the_field(): void
    {
        $this->graduate('BSc Computer Science');

        $fit = $this->fit($this->abroad());

        $this->assertTrue($fit->meetsRequirements(), $this->messages($fit));
        $this->assertStringContainsString('your programme, BSc Computer Science, fits', $this->programmeOutcome($fit)->message);
    }

    public function test_an_accounting_graduate_does_not_match_an_ict_masters_abroad(): void
    {
        $this->graduate('BCom Accounting');

        $fit = $this->fit($this->abroad());

        $this->assertFalse($fit->meetsRequirements());
        $this->assertStringContainsString('your programme is BCom Accounting', $this->programmeOutcome($fit)->message);
    }

    public function test_an_a_level_student_in_ict_cannot_go_straight_to_a_masters_abroad(): void
    {
        $this->leaver(['BSc Computer Science']);

        $this->assertFalse($this->fit($this->abroad())->meetsRequirements());
    }

    // --------------------------------------------- 3. fields to study next --

    public function test_a_graduate_who_wants_ict_next_matches_an_ict_masters_abroad(): void
    {
        $this->graduate('BCom Accounting', ['061']);

        $fit = $this->fit($this->abroad());

        $this->assertTrue($fit->meetsRequirements(), $this->messages($fit));
        $this->assertStringContainsString('the field you want to study next', $this->programmeOutcome($fit)->message);
    }

    public function test_a_refusal_names_both_the_programme_and_the_fields_wanted_next(): void
    {
        $this->graduate('BCom Accounting', ['042']);

        $message = $this->programmeOutcome($this->fit($this->abroad()))->message;

        $this->assertStringContainsString('your programme is BCom Accounting', $message);
        $this->assertStringContainsString('the field you want to study next is', $message);
    }

    public function test_a_broad_field_wanted_next_matches_a_listing_open_to_that_broad_field(): void
    {
        $this->graduate('BCom Accounting', ['07']);

        $this->assertTrue($this->fit($this->listing(['education_level' => EducationLevel::MASTERS], ['07']))->meetsRequirements());
    }

    // ------------------------------------------- 4. "all programmes" on purpose --

    public function test_all_programmes_on_purpose_outranks_a_field_named_in_the_words(): void
    {
        $this->graduate('BSc Computer Science');

        $listing = $this->listing(['education_level' => EducationLevel::MASTERS, 'description' => 'This bursary is open to Law students only.']);

        $this->assertTrue($this->fit($listing)->meetsRequirements(), $this->messages($this->fit($listing)));
        $this->assertNull($this->programmeOutcome($this->fit($listing)));
    }

    public function test_an_unanswered_old_listing_still_reads_its_words(): void
    {
        $this->graduate('BSc Computer Science');

        $listing = $this->listing(['education_level' => EducationLevel::MASTERS, 'description' => 'This bursary is open to Law students only.']);
        $listing->forceFill(['open_to_all_programmes' => null, 'any_institution' => null])->save();

        $this->assertFalse($this->fit($listing->fresh())->meetsRequirements());
    }

    // ------------------------------------------------- 5. also check yourself --

    public function test_other_conditions_are_shown_and_never_decide(): void
    {
        $this->graduate('BSc Computer Science');

        $fit = $this->fit($this->abroad(['other_conditions' => "IELTS 6.5 or higher\nTwo years of work experience"]));

        $this->assertTrue($fit->meetsRequirements());
        $notes = array_map(fn (RequirementOutcome $o) => $o->message, $fit->advisoryNotes());
        $this->assertContains('Also check yourself: IELTS 6.5 or higher', $notes);
        $this->assertContains('Also check yourself: Two years of work experience', $notes);
    }

    public function test_the_scholarship_page_shows_the_conditions_to_check(): void
    {
        $this->graduate('BSc Computer Science');
        $listing = $this->abroad(['other_conditions' => 'IELTS 6.5 or higher']);

        $this->actingAs($this->student)->get('/scholarships/' . $listing->opportunity_id)
            ->assertOk()
            ->assertSee('Also check yourself: IELTS 6.5 or higher');
    }
}
