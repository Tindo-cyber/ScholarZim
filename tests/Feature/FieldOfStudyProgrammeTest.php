<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Programme;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\GivesRequiredDocuments;
use Tests\TestCase;

/**
 * "Field of study" is a programme chosen from the catalogue, at the applicant's own level.
 *
 * For a Certificate, a Diploma, an Undergraduate and everything above, the profile used to ask for the field in a
 * free-text box and, separately, for the programme in a list lower down: two answers to one question, one of which
 * ScholarFit could not match. There is now one - the field of study IS the programme - on the profile form and on
 * the apply page alike. Choosing it saves the programme and writes its name as the field of study.
 */
class FieldOfStudyProgrammeTest extends TestCase
{
    use GivesRequiredDocuments;
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function at(string $level, array $more = []): void
    {
        $this->profile()->forceFill(array_merge(['education_level' => $level, 'field_of_study' => null], $more))->save();
        ApplicantProgramme::where('profile_id', $this->profile()->profile_id)->delete();
    }

    private function programmeAt(string $level, int $skip = 0): Programme
    {
        return Programme::where('education_level', $level)->where('status', Programme::APPROVED)->where('is_active', true)
            ->orderBy('name')->skip($skip)->firstOrFail();
    }

    private function current(): ?ApplicantProgramme
    {
        return ApplicantProgramme::where('profile_id', $this->profile()->profile_id)->where('kind', ApplicantProgramme::CURRENT)->first();
    }

    private function saveProfile(array $more = [])
    {
        return $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), array_merge([
            'full_name' => $this->student->full_name, 'gender' => 'male', 'province' => 'Harare',
            'institution_name' => 'Some College', 'biography' => 'Keen to learn.',
        ], $more));
    }

    private function page(): string
    {
        return $this->actingAs($this->student->fresh())->get(route('applicant.profile'))->assertOk()->getContent();
    }

    // -------------------------------------------------------------- the page --

    public function test_the_field_of_study_is_a_list_of_the_programmes_at_the_students_level(): void
    {
        foreach ([EducationLevel::CERTIFICATE, EducationLevel::DIPLOMA, EducationLevel::UNDERGRADUATE, EducationLevel::POSTGRADUATE] as $level) {
            $this->at($level);
            $html = $this->page();

            $this->assertStringContainsString('Field of study (your programme)', $html, $level);
            $this->assertStringContainsString('name="current_programme_id"', $html, $level);
            $this->assertStringNotContainsString('name="field_of_study"', $html, "$level: no free-text box");
            $this->assertStringNotContainsString('Your field, in your own words', $html, $level);

            $own = $this->programmeAt($level);
            $other = Programme::where('education_level', '!=', $level)->where('status', Programme::APPROVED)->where('is_active', true)->firstOrFail();

            $this->assertMatchesRegularExpression('/<option value="' . $own->id . '"[^>]*>\s*' . preg_quote(e($own->name), '/') . '/', $html, "$level: its own programmes are offered");
            $this->assertDoesNotMatchRegularExpression('/<option value="' . $other->id . '"[^>]*>\s*' . preg_quote(e($other->name), '/') . '/', $html, "$level: another level's are not");
        }
    }

    public function test_the_programme_section_no_longer_asks_for_the_same_thing_again(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE);
        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'name="current_programme_id"'), 'asked once on the page');
        $this->assertStringContainsString('chosen as your', $html);
        $this->assertStringContainsString('name="current_institution_id"', $html, 'where they studied is still asked there');
    }

    public function test_a_saved_programme_is_shown_chosen(): void
    {
        $this->at(EducationLevel::DIPLOMA);
        $programme = $this->programmeAt(EducationLevel::DIPLOMA);
        $this->saveProfile(['education_level' => EducationLevel::DIPLOMA, 'current_programme_id' => $programme->id])->assertSessionHasNoErrors();

        $this->assertMatchesRegularExpression('/<option value="' . $programme->id . '"\s+selected/', $this->page());
    }

    public function test_an_old_free_text_answer_with_no_programme_is_shown_and_the_student_is_asked_to_choose(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE, ['field_of_study' => 'Information Systems']);

        $html = $this->page();

        $this->assertStringContainsString('Earlier you wrote "Information Systems"', $html);
    }

    // --------------------------------------------------------------- saving --

    public function test_choosing_a_programme_saves_it_and_writes_its_name_as_the_field_of_study(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE);
        $programme = $this->programmeAt(EducationLevel::UNDERGRADUATE);

        $this->saveProfile(['education_level' => EducationLevel::UNDERGRADUATE, 'current_programme_id' => $programme->id])
            ->assertRedirect(route('applicant.profile'))->assertSessionHasNoErrors();

        $this->assertSame($programme->id, (int) $this->current()->programme_id);
        $this->assertSame($programme->name, $this->profile()->field_of_study);
        $this->assertSame($programme->id, $this->profile()->current_programme_id);
    }

    public function test_choosing_another_replaces_it_and_choosing_none_clears_it_and_the_field(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE);
        $first = $this->programmeAt(EducationLevel::UNDERGRADUATE);
        $second = $this->programmeAt(EducationLevel::UNDERGRADUATE, 1);

        $this->saveProfile(['education_level' => EducationLevel::UNDERGRADUATE, 'current_programme_id' => $first->id]);
        $this->saveProfile(['education_level' => EducationLevel::UNDERGRADUATE, 'current_programme_id' => $second->id]);

        $this->assertSame(1, ApplicantProgramme::where('profile_id', $this->profile()->profile_id)->count());
        $this->assertSame($second->name, $this->profile()->field_of_study);

        $this->saveProfile(['education_level' => EducationLevel::UNDERGRADUATE, 'current_programme_id' => '']);

        $this->assertNull($this->current());
        $this->assertNull($this->profile()->field_of_study);
    }

    public function test_where_they_studied_and_the_fields_they_want_next_are_left_alone(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE);
        $first = $this->programmeAt(EducationLevel::UNDERGRADUATE);
        $second = $this->programmeAt(EducationLevel::UNDERGRADUATE, 1);
        $institution = Institution::active()->firstOrFail();

        $this->actingAs($this->student)->post('/applicant/profile/programmes', ['current_programme_id' => $first->id, 'current_institution_id' => $institution->id]);
        $this->saveProfile(['education_level' => EducationLevel::UNDERGRADUATE, 'current_programme_id' => $second->id]);

        $this->assertSame($institution->id, (int) $this->current()->institution_id);
    }

    public function test_the_programme_sections_own_save_keeps_the_programme_chosen_above(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE);
        $programme = $this->programmeAt(EducationLevel::UNDERGRADUATE);
        $institution = Institution::active()->firstOrFail();
        $this->saveProfile(['education_level' => EducationLevel::UNDERGRADUATE, 'current_programme_id' => $programme->id]);

        $this->actingAs($this->student)->post('/applicant/profile/programmes', ['current_institution_id' => $institution->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($programme->id, (int) $this->current()->programme_id);
        $this->assertSame($institution->id, (int) $this->current()->institution_id);
        $this->assertSame($programme->name, $this->profile()->field_of_study);
    }

    // ------------------------------------------------------------- refusals --

    public function test_a_programme_from_another_level_is_refused_in_words_and_nothing_else_is_saved(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE);
        $diploma = $this->programmeAt(EducationLevel::DIPLOMA);
        $before = $this->profile()->biography;

        $this->saveProfile(['education_level' => EducationLevel::UNDERGRADUATE, 'current_programme_id' => $diploma->id, 'biography' => 'Should not be saved.'])
            ->assertSessionHasErrors('current_programme_id');

        $this->assertStringContainsString('is at Diploma level', session('errors')->first('current_programme_id'));
        $this->assertSame($before, $this->profile()->biography);
        $this->assertNull($this->current());
    }

    public function test_an_unknown_programme_is_refused(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE);

        $this->saveProfile(['education_level' => EducationLevel::UNDERGRADUATE, 'current_programme_id' => 999999])->assertSessionHasErrors('current_programme_id');
        $this->saveProfile(['education_level' => EducationLevel::UNDERGRADUATE, 'current_programme_id' => 'abc'])->assertSessionHasErrors('current_programme_id');

        $this->assertNull($this->current());
    }

    public function test_changing_the_level_does_not_refuse_the_programme_that_was_already_chosen(): void
    {
        $this->at(EducationLevel::DIPLOMA);
        $diploma = $this->programmeAt(EducationLevel::DIPLOMA);
        $this->saveProfile(['education_level' => EducationLevel::DIPLOMA, 'current_programme_id' => $diploma->id]);

        // The select still shows the old programme when the level is changed in the same save: that is not a new choice.
        $this->saveProfile(['education_level' => EducationLevel::UNDERGRADUATE, 'current_programme_id' => $diploma->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(EducationLevel::UNDERGRADUATE, $this->profile()->education_level);
    }

    public function test_a_school_leaver_has_no_programme_to_choose_here(): void
    {
        $this->at(EducationLevel::A_LEVEL);
        $programme = $this->programmeAt(EducationLevel::UNDERGRADUATE);

        $this->saveProfile(['education_level' => EducationLevel::A_LEVEL, 'current_programme_id' => $programme->id])->assertSessionHasNoErrors();

        $this->assertNull($this->current(), 'a school-leaver chooses the programmes they hope to study, up to three');
    }

    // -------------------------------------------------------- the apply page --

    public function test_the_apply_page_asks_for_the_field_of_study_as_a_programme_and_saves_it(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE, [
            'institution_name' => 'University of Zimbabwe', 'province' => 'Harare', 'locality' => 'Harare', 'gender' => 'male',
            'date_of_birth' => Carbon::today()->subYears(22)->toDateString(), 'biography' => 'Keen to learn.',
        ]);
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $listing = Opportunity::create([
            'provider_user_id' => $provider->user_id, 'provider_name' => 'Trust', 'title' => 'Field Award ' . uniqid(),
            'description' => 'Funding for learners in Zimbabwe.', 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'target_field' => 'Computer Science & IT',
        ]);
        $programme = $this->programmeAt(EducationLevel::UNDERGRADUATE);
        $this->giveRequiredDocuments($this->student);

        $page = $this->actingAs($this->student->fresh())->get(route('applications.wizard', $listing->opportunity_id))->assertOk()->getContent();
        $this->assertStringContainsString('name="profile[current_programme_id]"', $page);
        $this->assertStringNotContainsString('name="profile[field_of_study]"', $page);

        $this->actingAs($this->student->fresh())->post(route('applications.details', $listing->opportunity_id), ['profile' => ['current_programme_id' => $programme->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame($programme->id, (int) $this->current()->programme_id);
        $this->assertSame($programme->name, $this->profile()->field_of_study);
    }

    public function test_the_apply_page_refuses_a_missing_or_wrong_programme_in_plain_words(): void
    {
        $this->at(EducationLevel::UNDERGRADUATE, ['institution_name' => 'UZ', 'province' => 'Harare', 'gender' => 'male', 'biography' => 'Keen.']);
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $listing = Opportunity::create([
            'provider_user_id' => $provider->user_id, 'provider_name' => 'Trust', 'title' => 'Field Award ' . uniqid(),
            'description' => 'Funding for learners in Zimbabwe.', 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'target_field' => 'Computer Science & IT',
        ]);

        $this->actingAs($this->student->fresh())->post(route('applications.details', $listing->opportunity_id), ['profile' => ['current_programme_id' => '']])
            ->assertSessionHasErrors(['profile.current_programme_id' => 'Please choose your programme from the list.']);
    }
}
