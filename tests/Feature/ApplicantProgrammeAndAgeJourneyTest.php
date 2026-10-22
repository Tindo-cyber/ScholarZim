<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
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
 * The applicant's side of the same two things: which programme they record (chosen, suggested, kept when something else
 * on the profile is saved) and how that, and their age, reach the eligibility decision - the same decision the
 * recommendations, the detail page and the application gate all read.
 */
class ApplicantProgrammeAndAgeJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->profile()->forceFill(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => null])->save();
        ApplicantProgramme::query()->delete();
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

    private function enrolled(Programme $programme): void
    {
        ApplicantProgramme::query()->delete();
        ApplicantProgramme::create(['profile_id' => $this->profile()->profile_id, 'programme_id' => $programme->id, 'kind' => 'current']);
    }

    private function saveProgrammes(array $data)
    {
        return $this->actingAs($this->student->fresh())->post('/applicant/profile/programmes', $data);
    }

    private function saveProfile(array $more = [])
    {
        return $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), array_merge([
            'full_name' => $this->student->full_name, 'gender' => 'male', 'province' => 'Harare',
            'institution_name' => 'Some College', 'biography' => 'Keen to learn.',
        ], $more));
    }

    private function listing(array $scope = [], array $attributes = []): Opportunity
    {
        $listing = Opportunity::create($attributes + [
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->value('user_id'),
            'provider_name' => 'Journey Provider',
            'title' => 'A Journey Bursary',
            'description' => 'A listing used to exercise programme scope and age.',
            'education_level' => EducationLevel::MASTERS,
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

        return new EligibilityResult($listing, app(EligibilityEvaluator::class)->evaluate($profile, $listing, AcademicRecord::fromProfile($profile)), true);
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

    // ----------------------------------------------------- keeping what was saved --

    public function test_saving_an_unrelated_profile_detail_keeps_the_programme_and_its_field_of_study(): void
    {
        $programme = $this->programme('BSc Computer Science');
        $this->actingAs($this->student)->post('/applicant/profile/programmes', ['current_programme_id' => $programme->id]);
        $before = $this->profile()->fresh();

        $this->assertSame($programme->name, $before->field_of_study, 'the field of study is the programme');

        $this->saveProfile(['biography' => 'A different biography.'])->assertSessionHasNoErrors();

        $after = $this->profile()->fresh();

        $this->assertSame('A different biography.', $after->biography);
        $this->assertSame($programme->id, ApplicantProgramme::where('profile_id', $after->profile_id)->where('kind', 'current')->value('programme_id'));
        $this->assertSame($programme->name, $after->field_of_study);
    }

    public function test_the_programme_card_keeps_the_fields_chosen_for_next_when_a_missing_programme_is_added(): void
    {
        $field = Field::where('code', '053')->firstOrFail();

        $this->saveProgrammes(['target_field_ids' => [$field->id]])->assertSessionHasNoErrors();
        $this->saveProgrammes(['target_field_ids' => [$field->id], 'programme_suggestion' => 'BSc Quantum Basketweaving', 'programme_suggestion_field' => $field->id])
            ->assertSessionHasNoErrors();

        $suggested = Programme::where('name', 'BSc Quantum Basketweaving')->firstOrFail();

        $this->assertTrue($suggested->isPending());
        $this->assertSame($suggested->id, ApplicantProgramme::where('profile_id', $this->profile()->profile_id)->where('kind', 'current')->value('programme_id'));
        $this->assertSame($suggested->name, $this->profile()->fresh()->field_of_study, 'the profile shows the suggested programme as the field of study');
        $this->assertSame(1, \App\Models\ApplicantTargetField::where('profile_id', $this->profile()->profile_id)->count());
    }

    public function test_a_suggestion_that_matches_a_catalogue_programme_reuses_it_for_enrolled_and_school_leavers(): void
    {
        $existing = $this->programme('BSc Computer Science');
        $field = Field::where('code', '053')->firstOrFail();
        $count = Programme::count();

        $this->saveProgrammes(['programme_suggestion' => strtoupper($existing->name), 'programme_suggestion_field' => $field->id])->assertSessionHasNoErrors();

        $this->assertSame($count, Programme::count());
        $this->assertSame($existing->id, ApplicantProgramme::where('profile_id', $this->profile()->profile_id)->where('kind', 'current')->value('programme_id'));

        $this->profile()->forceFill(['education_level' => EducationLevel::A_LEVEL])->save();
        ApplicantProgramme::query()->delete();

        $this->saveProgrammes(['intended_programme_ids' => [$existing->id], 'programme_suggestion' => $existing->name, 'programme_suggestion_field' => $field->id,
            'programme_suggestion_level' => EducationLevel::UNDERGRADUATE])->assertSessionHasNoErrors();

        $this->assertSame(1, ApplicantProgramme::where('profile_id', $this->profile()->profile_id)->where('kind', 'intended')->count(), 'the same programme twice is kept once');
        $this->assertSame($count, Programme::count());
    }

    public function test_a_school_leaver_cannot_get_past_three_by_suggesting_one_more(): void
    {
        $this->profile()->forceFill(['education_level' => EducationLevel::A_LEVEL])->save();
        $ids = Programme::inCatalogue()->where('education_level', EducationLevel::UNDERGRADUATE)->orderBy('id')->limit(3)->pluck('id')->all();
        $field = Field::where('code', '053')->firstOrFail();

        $this->saveProgrammes(['intended_programme_ids' => $ids])->assertSessionHasNoErrors();
        $this->saveProgrammes(['intended_programme_ids' => $ids, 'programme_suggestion' => 'BSc Quantum Basketweaving', 'programme_suggestion_field' => $field->id,
            'programme_suggestion_level' => EducationLevel::UNDERGRADUATE])->assertSessionHasErrors('intended_programme_ids');

        $this->assertSame(3, ApplicantProgramme::where('profile_id', $this->profile()->profile_id)->where('kind', 'intended')->count(), 'what was saved before is untouched');
        $this->assertSame(0, ApplicantProgramme::whereHas('programme', fn ($q) => $q->where('name', 'BSc Quantum Basketweaving'))->count());
    }

    public function test_a_school_leaver_suggestion_needs_a_supported_level_and_a_real_field(): void
    {
        $this->profile()->forceFill(['education_level' => EducationLevel::O_LEVEL])->save();
        $field = Field::where('code', '053')->firstOrFail();

        foreach ([[EducationLevel::PHD, $field->id, 'programme_suggestion_level'], [EducationLevel::MASTERS, $field->id, 'programme_suggestion_level'],
            [EducationLevel::UNDERGRADUATE, 999999, 'programme_suggestion_field']] as [$level, $fieldId, $errorKey]) {
            $this->saveProgrammes(['programme_suggestion' => 'Some New Programme', 'programme_suggestion_field' => $fieldId, 'programme_suggestion_level' => $level])
                ->assertSessionHasErrors($errorKey);
        }

        $this->assertSame(0, Programme::where('name', 'Some New Programme')->count());
    }

    public function test_the_profile_page_shows_the_field_beside_each_programme_and_where_to_add_a_missing_one(): void
    {
        $programme = $this->programme('BSc Computer Science');
        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();

        $this->assertStringContainsString($programme->name . ' &middot; ' . e($programme->field->label()), $html);
        $this->assertStringContainsString('href="#programme-card"', $html);
        $this->assertStringContainsString("My programme isn't listed", $html);
        $this->assertStringContainsString('id="programme-card"', $html);
    }

    // -------------------------------------- the applicant's programme reaches ScholarFit --

    public function test_a_specific_programme_scholarship_does_not_match_another_programme_in_the_same_field(): void
    {
        $mine = $this->programme('BSc Computer Science');
        $sibling = Programme::inCatalogue()->where('education_level', EducationLevel::UNDERGRADUATE)->where('field_id', $mine->field_id)->where('id', '!=', $mine->id)->firstOrFail();

        $this->enrolled($mine);

        // A Master's award, so the student is at the level below it; open to the sibling programme only.
        $listing = $this->listing(['programme' => [$sibling->id]], ['education_level' => EducationLevel::UNDERGRADUATE]);
        $this->profile()->forceFill(['education_level' => EducationLevel::UNDERGRADUATE])->save();

        $scope = $this->outcome($this->fit($listing), RequirementOutcome::TYPE_PROGRAMME_SCOPE);

        $this->assertNotNull($scope);
        $this->assertFalse($scope->passed, 'choosing one programme does not open the award to the rest of its field');
    }

    public function test_a_field_wide_scholarship_matches_a_programme_inside_the_field(): void
    {
        $mine = $this->programme('BSc Computer Science');
        $this->enrolled($mine);

        $listing = $this->listing(['field' => [$mine->field_id]]);
        $scope = $this->outcome($this->fit($listing), RequirementOutcome::TYPE_PROGRAMME_SCOPE);

        $this->assertNotNull($scope);
        $this->assertTrue($scope->passed);
    }

    public function test_a_field_wide_scholarship_does_not_match_a_programme_outside_it(): void
    {
        $other = Programme::inCatalogue()->where('education_level', EducationLevel::UNDERGRADUATE)
            ->where('field_id', '!=', $this->programme('BSc Computer Science')->field_id)->firstOrFail();
        $this->enrolled($other);

        $listing = $this->listing(['field' => [$this->programme('BSc Computer Science')->field_id]]);
        $scope = $this->outcome($this->fit($listing), RequirementOutcome::TYPE_PROGRAMME_SCOPE);

        $this->assertFalse($scope->passed);
    }

    public function test_a_pending_suggestion_matches_through_its_field_only(): void
    {
        $field = Field::where('code', '053')->firstOrFail();
        $this->saveProgrammes(['programme_suggestion' => 'BSc Quantum Basketweaving', 'programme_suggestion_field' => $field->id])->assertSessionHasNoErrors();
        $pending = Programme::where('name', 'BSc Quantum Basketweaving')->firstOrFail();

        $byProgramme = $this->outcome($this->fit($this->listing(['programme' => [$pending->id]])), RequirementOutcome::TYPE_PROGRAMME_SCOPE);
        $byField = $this->outcome($this->fit($this->listing(['field' => [$field->id]])), RequirementOutcome::TYPE_PROGRAMME_SCOPE);
        $exactOther = $this->outcome($this->fit($this->listing(['programme' => [$this->programme('BSc Computer Science')->id]])), RequirementOutcome::TYPE_PROGRAMME_SCOPE);

        $this->assertTrue($byField->passed);
        $this->assertFalse($exactOther->passed, 'a suggestion is never an exact match for a listed programme');
        $this->assertNotNull($byProgramme);
    }

    // ------------------------------------------------------------- the age rule --

    private function withBirthday(?Carbon $dob): void
    {
        $this->profile()->forceFill(['date_of_birth' => $dob?->toDateString()])->save();
    }

    public function test_an_applicant_over_the_maximum_age_is_ineligible_and_one_within_it_passes(): void
    {
        $listing = $this->listing([], ['max_age' => 25]);

        $this->withBirthday(Carbon::today()->addDays(30)->subYears(26));
        $over = $this->fit($listing);
        $this->assertTrue($over->isIneligible());
        $this->assertFalse($this->outcome($over, RequirementOutcome::TYPE_AGE)->passed);

        $this->withBirthday(Carbon::today()->addDays(30)->subYears(25));
        $this->assertTrue($this->outcome($this->fit($listing), RequirementOutcome::TYPE_AGE)->passed, 'exactly the maximum age on the closing date still qualifies');

        $this->withBirthday(Carbon::today()->subYears(18));
        $this->assertTrue($this->outcome($this->fit($listing), RequirementOutcome::TYPE_AGE)->passed);
    }

    public function test_a_missing_date_of_birth_asks_for_it_instead_of_deciding(): void
    {
        $listing = $this->listing([], ['max_age' => 25]);
        $this->withBirthday(null);

        $fit = $this->fit($listing);
        $age = $this->outcome($fit, RequirementOutcome::TYPE_AGE);

        $this->assertFalse($age->passed);
        $this->assertTrue($fit->needsInformation());
        $this->assertFalse($fit->isIneligible(), 'not a failure: the answer is simply not known yet');
        $this->assertStringContainsString('add your date of birth', $age->message);
    }

    public function test_changing_the_maximum_age_changes_the_decision(): void
    {
        $listing = $this->listing([], ['max_age' => 30]);
        $this->withBirthday(Carbon::today()->subYears(28));

        $this->assertTrue($this->outcome($this->fit($listing), RequirementOutcome::TYPE_AGE)->passed);

        $listing->update(['max_age' => 20]);

        $this->assertFalse($this->outcome($this->fit($listing->fresh()), RequirementOutcome::TYPE_AGE)->passed);
    }

    public function test_the_detail_page_and_the_decision_agree_about_age(): void
    {
        $listing = $this->listing([], ['max_age' => 20]);
        $this->withBirthday(Carbon::today()->subYears(28));

        $html = $this->actingAs($this->student->fresh())->get('/scholarships/' . $listing->opportunity_id)->assertOk()->getContent();

        $this->assertStringContainsString('Age: 20 and under required', $html);
        $this->assertFalse($this->fit($listing)->meetsRequirements());
    }

    public function test_the_profile_still_refuses_an_applicant_under_twelve(): void
    {
        $this->saveProfile(['date_of_birth' => Carbon::today()->subYears(11)->toDateString()])->assertSessionHasErrors('date_of_birth');
        $this->saveProfile(['date_of_birth' => Carbon::today()->subYears(12)->toDateString()])->assertSessionHasNoErrors();
    }
}
