<?php

namespace Tests\Feature;

use App\Models\ApplicantInstitution;
use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Services\Catalogue\ProgrammeCatalogue;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\RequirementOutcome;
use App\Services\ScholarFit\ScholarFitFieldNames;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Whether an applicant's programme and institution fall inside what a listing is open to.
 *
 * Programmes and fields are one group (any programme listed, or inside any field listed);
 * institutions are another; with both, a student must fit both. Nothing is loosened beyond what
 * the provider chose. What the applicant has not said is "needs information", never a fail -
 * except where nothing could ever fit (a Primary pupil has no programme).
 */
class ProgrammeScopeEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        // The seeded student comes with a field of study; each test says what it needs.
        $this->profile()->forceFill(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => null, 'degree_classification' => null])->save();
    }

    // ----------------------------------------------------------------- helpers --

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function programme(string $name): Programme
    {
        return Programme::where('name', $name)->firstOrFail();
    }

    private function field(string $code): Field
    {
        return Field::where('code', $code)->firstOrFail();
    }

    private function institution(string $code): Institution
    {
        return Institution::where('code', $code)->firstOrFail();
    }

    /** The student is on this programme (and, optionally, at this institution). */
    private function enrolled(string $programme, ?string $institution = null): void
    {
        ApplicantProgramme::query()->delete();
        ApplicantProgramme::create([
            'profile_id' => $this->profile()->profile_id, 'programme_id' => $this->programme($programme)->id, 'kind' => 'current',
            'institution_id' => $institution ? $this->institution($institution)->id : null,
        ]);
    }

    /** The student is a school-leaver hoping to study these. */
    private function leaver(array $programmes, array $applied = [], string $level = EducationLevel::A_LEVEL): void
    {
        $this->profile()->forceFill(['education_level' => $level])->save();
        ApplicantProgramme::query()->delete();
        ApplicantInstitution::query()->delete();

        foreach ($programmes as $name) {
            ApplicantProgramme::create(['profile_id' => $this->profile()->profile_id, 'programme_id' => $this->programme($name)->id, 'kind' => 'intended']);
        }

        foreach ($applied as $code) {
            ApplicantInstitution::create(['profile_id' => $this->profile()->profile_id, 'institution_id' => $this->institution($code)->id]);
        }
    }

    /** @param  array<string, array<int, int>>  $scope  programme/field/institution => ids */
    private function listing(array $scope = [], array $attributes = []): Opportunity
    {
        $listing = Opportunity::create($attributes + [
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->value('user_id'),
            'provider_name' => 'Scope Provider',
            'title' => 'A Bursary',
            'description' => 'A listing used to exercise programme scope.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now(),
        ]);

        foreach ($scope as $column => $ids) {
            foreach ($ids as $id) {
                OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, $column . '_id' => $id]);
            }
        }

        return $listing->fresh();
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

    private function outcome(EligibilityResult $fit, string $type): ?RequirementOutcome
    {
        foreach ($fit->outcomes as $outcome) {
            if ($outcome->type === $type) {
                return $outcome;
            }
        }

        return null;
    }

    private function scopeOutcome(Opportunity $listing): ?RequirementOutcome
    {
        return $this->outcome($this->fit($listing), RequirementOutcome::TYPE_PROGRAMME_SCOPE);
    }

    private function institutionOutcome(Opportunity $listing): ?RequirementOutcome
    {
        return $this->outcome($this->fit($listing), RequirementOutcome::TYPE_INSTITUTION_SCOPE);
    }

    // ------------------------------------------------------------- no scope --

    public function test_a_listing_open_to_anything_states_no_programme_rule(): void
    {
        $this->enrolled('BSc Computer Science');

        $fit = $this->fit($this->listing());

        $this->assertNull($this->outcome($fit, RequirementOutcome::TYPE_PROGRAMME_SCOPE));
        $this->assertNull($this->outcome($fit, RequirementOutcome::TYPE_INSTITUTION_SCOPE));
    }

    // ----------------------------------------------------- programmes and fields --

    public function test_the_exact_programme_passes_as_a_programme_fit(): void
    {
        $this->enrolled('BSc Computer Science');

        $outcome = $this->scopeOutcome($this->listing(['programme' => [$this->programme('BSc Computer Science')->id]]));

        $this->assertTrue($outcome->passed);
        $this->assertSame(RequirementOutcome::FIT_PROGRAMME, $outcome->fit);
    }

    public function test_any_one_of_several_listed_programmes_is_enough(): void
    {
        $this->enrolled('BSc Information Systems');

        $outcome = $this->scopeOutcome($this->listing(['programme' => [
            $this->programme('BSc Computer Science')->id, $this->programme('BSc Information Systems')->id,
        ]]));

        $this->assertTrue($outcome->passed);
    }

    public function test_a_narrow_field_passes_as_a_narrow_fit_and_a_broad_field_as_a_broad_fit(): void
    {
        $this->enrolled('BSc Computer Science');

        $narrow = $this->scopeOutcome($this->listing(['field' => [$this->field('061')->id]]));
        $broad = $this->scopeOutcome($this->listing(['field' => [$this->field('06')->id]]));

        $this->assertSame(RequirementOutcome::FIT_NARROW_FIELD, $narrow->fit);
        $this->assertSame(RequirementOutcome::FIT_BROAD_FIELD, $broad->fit);
        $this->assertTrue($narrow->passed && $broad->passed);
    }

    public function test_the_best_of_several_fits_is_reported(): void
    {
        $this->enrolled('BSc Computer Science');

        $outcome = $this->scopeOutcome($this->listing([
            'field' => [$this->field('06')->id],
            'programme' => [$this->programme('BSc Computer Science')->id],
        ]));

        $this->assertSame(RequirementOutcome::FIT_PROGRAMME, $outcome->fit);
    }

    public function test_a_programme_outside_every_choice_fails_and_says_both_sides(): void
    {
        $this->enrolled('Bachelor of Laws');

        $outcome = $this->scopeOutcome($this->listing(['field' => [$this->field('07')->id], 'programme' => [$this->programme('BSc Computer Science')->id]]));

        $this->assertFalse($outcome->passed);
        $this->assertFalse($outcome->needsInformation);
        $this->assertStringContainsString('BSc Computer Science', $outcome->message);
        $this->assertStringContainsString('any programme in Engineering and construction', $outcome->message);
        $this->assertStringContainsString('Bachelor of Laws', $outcome->message);
    }

    public function test_civil_engineering_is_inside_the_broad_engineering_field_but_not_the_narrow_one(): void
    {
        $this->enrolled('BEng Civil Engineering');

        $this->assertTrue($this->scopeOutcome($this->listing(['field' => [$this->field('07')->id]]))->passed);
        $this->assertFalse($this->scopeOutcome($this->listing(['field' => [$this->field('071')->id]]))->passed, 'the narrow field is "engineering trades"; civil is in 073');
    }

    // --------------------------------------------------------- pending programmes --

    private function pending(string $name = 'BSc Quantum Basketweaving', string $fieldCode = '061'): Programme
    {
        return app(ProgrammeCatalogue::class)->suggest($this->student, $name, EducationLevel::UNDERGRADUATE, $this->field($fieldCode)->id);
    }

    public function test_a_pending_programme_matches_through_its_field(): void
    {
        $pending = $this->pending();
        ApplicantProgramme::create(['profile_id' => $this->profile()->profile_id, 'programme_id' => $pending->id, 'kind' => 'current']);

        $outcome = $this->scopeOutcome($this->listing(['field' => [$this->field('061')->id]]));

        $this->assertTrue($outcome->passed);
        $this->assertSame(RequirementOutcome::FIT_NARROW_FIELD, $outcome->fit, 'a field fit, never an exact programme');
    }

    public function test_a_pending_programme_never_matches_a_listed_programme_exactly(): void
    {
        $pending = $this->pending();
        ApplicantProgramme::create(['profile_id' => $this->profile()->profile_id, 'programme_id' => $pending->id, 'kind' => 'current']);

        $outcome = $this->scopeOutcome($this->listing(['programme' => [$this->programme('BSc Computer Science')->id]]));

        $this->assertFalse($outcome->passed);
    }

    public function test_a_listing_naming_a_pending_programme_is_open_to_its_field_until_it_is_approved(): void
    {
        $pending = $this->pending('BSc Quantum Basketweaving', '053');
        $this->enrolled('BSc Physics');

        $outcome = $this->scopeOutcome($this->listing(['programme' => [$pending->id]]));

        $this->assertTrue($outcome->passed, 'Physics is in the same narrow field (053)');
        $this->assertSame(RequirementOutcome::FIT_NARROW_FIELD, $outcome->fit);

        app(ProgrammeCatalogue::class)->approve($pending, User::where('email', 'admin@scholarzim.co.zw')->firstOrFail());

        $this->assertFalse($this->scopeOutcome($this->listing(['programme' => [$pending->id]]))->passed, 'approved: now it means exactly that programme');
    }

    // ------------------------------------------------------------ institutions --

    public function test_an_enrolled_student_at_a_listed_institution_passes(): void
    {
        $this->enrolled('BSc Computer Science', 'MSU');

        $outcome = $this->institutionOutcome($this->listing(['institution' => [$this->institution('MSU')->id, $this->institution('NUST')->id]]));

        $this->assertTrue($outcome->passed);
    }

    public function test_an_enrolled_student_at_another_institution_fails(): void
    {
        $this->enrolled('BSc Computer Science', 'UZ');

        $outcome = $this->institutionOutcome($this->listing(['institution' => [$this->institution('MSU')->id]]));

        $this->assertFalse($outcome->passed);
        $this->assertFalse($outcome->needsInformation);
        $this->assertStringContainsString('MSU', $outcome->message);
        $this->assertStringContainsString('University of Zimbabwe', $outcome->message);
    }

    public function test_an_enrolled_student_who_has_not_said_where_they_study_needs_information(): void
    {
        $this->enrolled('BSc Computer Science');

        $listing = $this->listing(['institution' => [$this->institution('MSU')->id]]);
        $outcome = $this->institutionOutcome($listing);

        $this->assertTrue($outcome->needsInformation);
        $this->assertSame(ScholarFitFieldNames::INSTITUTION, $outcome->missing);
        $this->assertTrue($this->fit($listing)->needsInformation());
        $this->assertFalse($this->fit($listing)->isIneligible());
    }

    public function test_a_school_leaver_with_no_institution_recorded_needs_information_not_a_fail(): void
    {
        $this->leaver(['BSc Computer Science']);

        $listing = $this->listing(['institution' => [$this->institution('MSU')->id]]);

        $this->assertTrue($this->institutionOutcome($listing)->needsInformation);
        $this->assertTrue($this->fit($listing)->needsInformation());
    }

    public function test_a_school_leaver_who_applied_to_a_listed_institution_passes_and_to_none_fails(): void
    {
        $this->leaver(['BSc Computer Science'], ['MSU', 'UZ']);
        $this->assertTrue($this->institutionOutcome($this->listing(['institution' => [$this->institution('MSU')->id]]))->passed);

        $this->leaver(['BSc Computer Science'], ['UZ']);
        $this->assertFalse($this->institutionOutcome($this->listing(['institution' => [$this->institution('MSU')->id]]))->passed);
    }

    public function test_programme_and_institution_must_both_fit(): void
    {
        $scope = fn () => ['field' => [$this->field('06')->id], 'institution' => [$this->institution('MSU')->id, $this->institution('NUST')->id]];

        $this->enrolled('BSc Computer Science', 'MSU');
        $this->assertTrue($this->fit($this->listing($scope()))->meetsRequirements());

        $this->enrolled('BSc Computer Science', 'UZ');
        $this->assertTrue($this->fit($this->listing($scope()))->isIneligible(), 'right field, wrong institution');

        $this->enrolled('Bachelor of Laws', 'MSU');
        $this->assertTrue($this->fit($this->listing($scope()))->isIneligible(), 'right institution, wrong field');
    }

    public function test_a_failure_outranks_a_missing_answer(): void
    {
        $this->enrolled('Bachelor of Laws');   // wrong field, and no institution recorded

        $fit = $this->fit($this->listing(['field' => [$this->field('06')->id], 'institution' => [$this->institution('MSU')->id]]));

        $this->assertTrue($fit->isIneligible());
        $this->assertFalse($fit->needsInformation(), 'asking for a detail cannot change a no');
    }

    // ----------------------------------------------------------- school-leavers --

    public function test_a_school_leaver_matches_if_any_intended_programme_fits(): void
    {
        $this->leaver(['Bachelor of Laws', 'BSc Computer Science']);

        $outcome = $this->scopeOutcome($this->listing(['programme' => [$this->programme('BSc Computer Science')->id]]));

        $this->assertTrue($outcome->passed);
        $this->assertSame(RequirementOutcome::FIT_PROGRAMME, $outcome->fit);
    }

    public function test_a_school_leaver_none_of_whose_choices_fit_fails(): void
    {
        $this->leaver(['Bachelor of Laws', 'BSc Physics']);

        $this->assertFalse($this->scopeOutcome($this->listing(['field' => [$this->field('06')->id]]))->passed);
    }

    // ------------------------------------------------------------ nothing recorded --

    public function test_a_student_with_no_programme_recorded_needs_information(): void
    {
        ApplicantProgramme::query()->delete();

        $listing = $this->listing(['field' => [$this->field('06')->id]]);
        $outcome = $this->scopeOutcome($listing);

        $this->assertTrue($outcome->needsInformation);
        $this->assertSame(ScholarFitFieldNames::PROGRAMME, $outcome->missing);
        $this->assertSame('programme-card', ScholarFitFieldNames::anchor(ScholarFitFieldNames::PROGRAMME));
    }

    public function test_an_older_field_of_study_stands_in_for_a_field_scope_until_a_programme_is_chosen(): void
    {
        ApplicantProgramme::query()->delete();
        $this->profile()->forceFill(['field_of_study' => 'Computer Science & IT'])->save();

        $this->assertTrue($this->scopeOutcome($this->listing(['field' => [$this->field('06')->id]]))->passed);
        $this->assertFalse($this->scopeOutcome($this->listing(['field' => [$this->field('04')->id]]))->passed);
    }

    public function test_an_older_field_of_study_cannot_stand_in_for_a_named_programme(): void
    {
        ApplicantProgramme::query()->delete();
        $this->profile()->forceFill(['field_of_study' => 'Computer Science & IT'])->save();

        $outcome = $this->scopeOutcome($this->listing(['programme' => [$this->programme('BSc Computer Science')->id]]));

        $this->assertTrue($outcome->needsInformation, 'a field is not a programme: ask which one');
    }

    public function test_a_primary_pupil_has_no_programme_so_a_programme_listing_fails_them(): void
    {
        $this->profile()->forceFill(['education_level' => EducationLevel::PRIMARY])->save();
        ApplicantProgramme::query()->delete();

        $outcome = $this->scopeOutcome($this->listing(['field' => [$this->field('06')->id]], ['education_level' => null]));

        $this->assertFalse($outcome->passed);
        $this->assertFalse($outcome->needsInformation);
    }

    // ---------------------------------------------- the older field rules step aside --

    public function test_the_older_field_setting_is_not_also_applied_when_a_scope_exists(): void
    {
        $this->enrolled('BSc Computer Science');
        $this->profile()->forceFill(['field_of_study' => 'Computer Science & IT'])->save();

        $fit = $this->fit($this->listing(['field' => [$this->field('06')->id]], ['target_field' => 'Law']));

        $this->assertNull($this->outcome($fit, RequirementOutcome::TYPE_FIELD));
        $this->assertTrue($fit->meetsRequirements());
    }

    public function test_the_older_field_setting_still_applies_when_there_is_no_scope(): void
    {
        $this->profile()->forceFill(['field_of_study' => 'Computer Science & IT'])->save();

        $this->assertNotNull($this->outcome($this->fit($this->listing([], ['target_field' => 'Law'])), RequirementOutcome::TYPE_FIELD));
    }

    // ------------------------------------------------------ the wording is a scope too --

    public function test_a_programme_named_in_the_title_is_applied_when_nothing_was_chosen(): void
    {
        $this->enrolled('BSc Information Systems');

        $fit = $this->fit($this->listing([], ['title' => 'BSc Computer Science Bursary']));

        $outcome = $this->outcome($fit, RequirementOutcome::TYPE_PROGRAMME_SCOPE);
        $this->assertFalse($outcome->passed);
        $this->assertStringContainsString('title', $outcome->message);
    }

    public function test_the_same_listing_passes_a_student_on_that_programme(): void
    {
        $this->enrolled('BSc Computer Science');

        $outcome = $this->scopeOutcome($this->listing([], ['title' => 'BSc Computer Science Bursary']));

        $this->assertTrue($outcome->passed);
        $this->assertSame(RequirementOutcome::FIT_PROGRAMME, $outcome->fit);
    }

    public function test_a_programme_mentioned_in_passing_in_the_description_is_not_a_rule(): void
    {
        $this->enrolled('BSc Information Systems');

        $outcome = $this->scopeOutcome($this->listing([], ['description' => 'Past recipients have gone on to study Bachelor of Laws and other degrees.']));

        $this->assertNull($outcome, 'no audience wording, so it is just a sentence');
    }

    public function test_a_programme_named_in_an_audience_sentence_is_a_rule(): void
    {
        $this->enrolled('BSc Information Systems');

        $outcome = $this->scopeOutcome($this->listing([], ['description' => 'This bursary is open to students enrolled in the Bachelor of Laws.']));

        $this->assertFalse($outcome->passed);
    }

    public function test_a_chosen_scope_silences_the_wording(): void
    {
        $this->enrolled('BSc Information Systems');

        $outcome = $this->scopeOutcome($this->listing(['field' => [$this->field('06')->id]], ['title' => 'BSc Computer Science Bursary']));

        $this->assertTrue($outcome->passed, 'the scope says ICT, the title is ignored');
    }

    // --------------------------------------------------------- the whole page --

    public function test_the_detail_page_explains_the_programme_rule_to_the_student(): void
    {
        $this->enrolled('Bachelor of Laws');
        $listing = $this->listing(['field' => [$this->field('06')->id]]);

        $this->actingAs($this->student)->get('/scholarships/' . $listing->opportunity_id)->assertOk()
            ->assertSee('open to any programme in Computing and ICT');
    }
}
