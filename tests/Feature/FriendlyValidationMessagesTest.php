<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\User;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\ProfileDetailRules;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * What a person is told when a form is refused.
 *
 * The profile form says "The gender field is required." and "The academic_subject_results.0.subject_id field is
 * required." by default: the name of a database column and an array position. These tests hold the replacement to
 * what an ordinary student can act on - in words, beside the field that needs attention, against the right row of the
 * results table, with everything else they typed still in the form.
 */
class FriendlyValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private AcademicQualification $aLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail();
        $this->aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $this->profile()->academicResults()->delete();
        $this->profile()->forceFill(['education_level' => EducationLevel::A_LEVEL, 'biography' => 'Before the save.'])->save();
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function subject(string $name): AcademicSubject
    {
        return AcademicSubject::where('qualification_id', $this->aLevel->id)->where('name', $name)->firstOrFail();
    }

    private function fields(array $overrides = []): array
    {
        return array_merge([
            'full_name' => $this->student->full_name,
            'education_level' => EducationLevel::A_LEVEL,
            'gender' => 'female',
            'institution_name' => 'Mutare Girls High School',
            'province' => 'Manicaland',
            'biography' => 'Aspiring doctor, typed this time.',
            'academic_results_submitted' => '1',
        ], $overrides);
    }

    private function row(?string $subject = 'Mathematics', ?string $result = 'A', mixed $year = 2024, bool $qualification = true): array
    {
        return array_filter([
            'qualification_id' => $qualification ? $this->aLevel->id : '',
            'subject_id' => $subject === null ? '' : $this->subject($subject)->id,
            'result' => $result ?? '',
            'year' => $year ?? '',
        ], static fn ($value) => $value !== null);
    }

    /** The errors the refusal flashed, read before the page that shows them is fetched. */
    private ?\Illuminate\Support\ViewErrorBag $errors = null;

    /** Post the profile form, then fetch the page it sends the person back to, as the browser does. */
    private function submit(array $fields): \Illuminate\Testing\TestResponse
    {
        $post = $this->actingAs($this->student)->from(route('applicant.profile'))->post(route('applicant.profile.update'), $fields);

        $this->errors = session('errors');

        return $this->get($post->headers->get('Location') ?? route('applicant.profile'))->assertOk();
    }

    /** What a person reads: the page without its markup, so a field name inside an attribute does not count. */
    private function visibleText(string $html): string
    {
        return html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    private function assertNoTechnicalLanguage(string $html): void
    {
        $text = $this->visibleText($html);

        $this->assertDoesNotMatchRegularExpression('/academic_subject_results|subject_id|qualification_id|\.\d+\.|profile\.\w+/', $text, 'a field name or array position is on the page');
        $this->assertDoesNotMatchRegularExpression('/\bfield (is|must)\b/i', $text, 'the word "field" in a validation message');
    }

    // ------------------------------------------------------------------ gender --

    public function test_submitting_without_a_gender_says_so_in_plain_words(): void
    {
        $response = $this->submit($this->fields(['gender' => '']));

        $response->assertSee('Please select your gender.');
        $this->assertSame('Please select your gender.', $this->errors?->first('gender'));
        $this->assertNoTechnicalLanguage($response->getContent());
    }

    public function test_the_gender_message_sits_beside_the_gender_choice_and_marks_it(): void
    {
        $html = $this->submit($this->fields(['gender' => '']))->getContent();

        $this->assertMatchesRegularExpression('/<fieldset[^>]*>.*?Gender.*?form-check-input\s+is-invalid.*?invalid-feedback d-block[^>]*>\s*Please select your gender\./s', $html);
    }

    public function test_an_unknown_gender_value_is_refused_in_the_same_words(): void
    {
        $this->submit($this->fields(['gender' => 'robot']))->assertSee('Please select your gender.');
    }

    public function test_the_gender_that_was_chosen_is_submitted_and_kept(): void
    {
        $this->submit($this->fields(['gender' => 'female']))->assertDontSee('Please select your gender.');

        $this->assertSame('female', $this->profile()->gender);
    }

    // ------------------------------------------------------- the subject rows --

    public function test_a_row_with_no_subject_asks_for_a_subject(): void
    {
        $response = $this->submit($this->fields(['academic_subject_results' => [0 => $this->row(subject: null, result: 'A')]]));

        $this->assertSame('Row 1: please select a subject.', $this->errors?->first('academic_subject_results.0.subject_id'));
        $response->assertSee('Please select a subject.');
        $this->assertNoTechnicalLanguage($response->getContent());
    }

    public function test_a_row_with_no_result_asks_for_the_grade(): void
    {
        $response = $this->submit($this->fields(['academic_subject_results' => [0 => $this->row(result: null)]]));

        $this->assertSame('Row 1: please enter the grade or result for this subject.', $this->errors?->first('academic_subject_results.0.result'));
        $response->assertSee('Please enter the grade or result for this subject.');
        $this->assertNoTechnicalLanguage($response->getContent());
    }

    public function test_a_row_with_only_a_qualification_is_told_what_is_missing_and_is_not_dropped(): void
    {
        $response = $this->submit($this->fields(['academic_subject_results' => [0 => $this->row(subject: null, result: null, year: null)]]));

        $errors = $this->errors;
        $this->assertTrue($errors->has('academic_subject_results.0.subject_id'));
        $this->assertTrue($errors->has('academic_subject_results.0.result'));
        $this->assertSame(0, $this->profile()->academicResults()->count(), 'nothing half-finished was saved');
        $this->assertNoTechnicalLanguage($response->getContent());
    }

    public function test_a_row_that_has_only_a_year_is_a_started_row_and_is_not_ignored(): void
    {
        $this->submit($this->fields(['academic_subject_results' => [0 => $this->row(subject: null, result: null, year: 2023, qualification: false)]]));

        $errors = $this->errors;
        foreach (['qualification_id', 'subject_id', 'result'] as $part) {
            $this->assertTrue($errors->has("academic_subject_results.0.$part"), $part);
        }
    }

    public function test_each_message_lands_on_its_own_row(): void
    {
        $html = $this->submit($this->fields(['academic_subject_results' => [
            0 => $this->row('Mathematics', 'A'),
            1 => $this->row('Physics', null),
            2 => $this->row(null, 'B'),
        ]]))->getContent();

        $html = explode('<template id="academic-result-template">', $html)[0]; // the blank row used by Add subject is not a row
        $rows = preg_split('/<tr class="academic-result-row">/', $html);
        array_shift($rows); // everything before the first row

        $this->assertCount(3, $rows);
        $this->assertStringNotContainsString('invalid-feedback', $rows[0], 'the complete row has no message');
        $this->assertStringContainsString('Please enter the grade or result for this subject.', $rows[1]);
        $this->assertStringNotContainsString('Please select a subject.', $rows[1]);
        $this->assertStringContainsString('Please select a subject.', $rows[2]);
        $this->assertStringNotContainsString('Please enter the grade', $rows[2]);
    }

    public function test_the_row_number_is_in_the_summary_so_the_person_knows_which_row(): void
    {
        $html = $this->submit($this->fields(['academic_subject_results' => [
            0 => $this->row('Mathematics', 'A'),
            1 => $this->row('Physics', null),
        ]]))->getContent();

        $this->assertStringContainsString('Row 2: please enter the grade or result for this subject.', $this->visibleText($html));
        $this->assertStringNotContainsString('Row 1:', $this->visibleText($html));
    }

    public function test_a_row_is_not_announced_in_a_second_alert_of_its_own(): void
    {
        $html = $this->submit($this->fields(['academic_subject_results' => [0 => $this->row('Physics', null)]]))->getContent();

        // Once in the summary (with its row number), once beside the field: no third place.
        $this->assertSame(1, substr_count($html, 'Row 1: please enter the grade or result for this subject.'));
        $this->assertSame(1, substr_count($html, '>Please enter the grade or result for this subject.<'));
        $this->assertSame(1, substr_count($html, 'Please fix the following:'));
    }

    // ------------------------------------------------------- nothing is lost --

    public function test_everything_the_person_typed_is_still_in_the_form_after_a_refusal(): void
    {
        $maths = $this->subject('Mathematics');
        $html = $this->submit($this->fields([
            'gender' => '',
            'academic_subject_results' => [
                0 => $this->row('Mathematics', 'A', 2024),
                3 => $this->row('Physics', null, 2023),
            ],
        ]))->getContent();

        $this->assertStringContainsString('Aspiring doctor, typed this time.', $html, 'the biography typed this time');
        $this->assertStringContainsString('Mutare Girls High School', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $maths->id . '"\s+selected[^>]*>/', $html, 'the chosen subject');
        $this->assertMatchesRegularExpression('/<option value="A"\s+selected[^>]*>A<\/option>/', $html, 'the chosen grade');
        $this->assertStringContainsString('value="2024"', $html);
        $this->assertStringContainsString('value="2023"', $html, 'a row that was not the first');
        $this->assertStringContainsString('name="academic_subject_results[3][subject_id]"', $html, 'row numbers are the ones submitted');
        $this->assertSame('Before the save.', $this->profile()->biography, 'and nothing was saved');
    }

    public function test_a_refused_save_changes_nothing_on_the_profile(): void
    {
        $this->submit($this->fields(['gender' => '', 'biography' => 'Should not be saved.']));

        $this->assertSame('Before the save.', $this->profile()->biography);
    }

    public function test_a_result_the_catalogue_refuses_does_not_leave_the_details_saved(): void
    {
        $this->profile()->forceFill(['education_level' => EducationLevel::PRIMARY, 'date_of_birth' => now()->subYears(12)->toDateString()])->save();

        // An A-Level row from a Primary pupil passes the form rules and is refused by the catalogue, after the details.
        $response = $this->submit($this->fields([
            'education_level' => EducationLevel::PRIMARY,
            'biography' => 'Saved only if the whole thing is.',
            'academic_subject_results' => [0 => $this->row('Mathematics', 'A')],
        ]));

        $this->assertSame('Before the save.', $this->profile()->biography);
        $this->assertSame(0, $this->profile()->academicResults()->count());
        $response->assertSee('is above the highest level you have completed');
        $this->assertNoTechnicalLanguage($response->getContent());
    }

    // ------------------------------------------- conditional on the education level --

    public function test_a_primary_applicant_is_not_refused_for_an_empty_results_row(): void
    {
        $this->profile()->forceFill(['education_level' => EducationLevel::PRIMARY, 'date_of_birth' => now()->subYears(12)->toDateString()])->save();

        $this->actingAs($this->student)->post(route('applicant.profile.update'), $this->fields([
            'education_level' => EducationLevel::PRIMARY,
            'academic_subject_results' => [0 => $this->row(subject: null, result: null, year: null, qualification: false)],
        ]))->assertRedirect(route('applicant.profile'))->assertSessionHasNoErrors();
    }

    public function test_an_empty_row_beside_a_real_one_is_set_aside_and_the_real_one_is_kept(): void
    {
        $this->actingAs($this->student)->post(route('applicant.profile.update'), $this->fields([
            'academic_subject_results' => [
                0 => $this->row('Mathematics', 'A'),
                1 => $this->row(subject: null, result: null, year: null, qualification: false),
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->profile()->academicResults()->count());
    }

    public function test_a_row_the_person_started_is_validated_whatever_their_level(): void
    {
        foreach ([EducationLevel::PRIMARY, EducationLevel::O_LEVEL, EducationLevel::A_LEVEL, EducationLevel::UNDERGRADUATE] as $level) {
            $this->submit($this->fields(['education_level' => $level, 'academic_subject_results' => [0 => $this->row(subject: null, result: null)]]));

            $this->assertTrue($this->errors->has('academic_subject_results.0.subject_id'), $level);
        }
    }

    // ------------------------------------------------ what stays mandatory --

    public function test_the_full_name_is_still_required_and_says_so_plainly(): void
    {
        $response = $this->submit($this->fields(['full_name' => '']));

        $response->assertSee('Please enter your full name.');
        $this->assertNoTechnicalLanguage($response->getContent());
    }

    public function test_the_results_section_can_still_be_emptied_on_purpose(): void
    {
        $this->profile()->academicResults()->create([
            'qualification_id' => $this->aLevel->id, 'subject_id' => $this->subject('Physics')->id, 'result' => 'B', 'year' => 2023, 'derived_points' => 3,
        ]);

        $this->actingAs($this->student)->post(route('applicant.profile.update'), $this->fields())->assertSessionHasNoErrors();

        $this->assertSame(0, $this->profile()->academicResults()->count(), 'removing every row is a deletion they can make');
    }

    // --------------------------------------------- the apply page and the rest --

    public function test_the_apply_pages_gender_and_document_messages_are_in_words_too(): void
    {
        $gender = Validator::make(['profile' => ['gender' => null]], ['profile.gender' => ProfileDetailRules::forMissing('gender')]);
        $this->assertSame('Please select your gender.', $gender->errors()->first('profile.gender'));

        $document = Validator::make(['documents' => ['recommendation' => null]], ['documents.recommendation' => ['required', 'file']]);
        $this->assertSame('Please choose a file for the recommendation letter.', $document->errors()->first('documents.recommendation'));

        $institutions = Validator::make(['profile' => ['applied_institution_ids' => []]], ['profile.applied_institution_ids' => ['required', 'array', 'min:1']]);
        $this->assertSame('Please choose at least one institution.', $institutions->errors()->first('profile.applied_institution_ids'));
    }

    public function test_the_ordinary_messages_no_longer_talk_about_fields(): void
    {
        $messages = Validator::make(
            ['email' => 'nope', 'age' => 'x', 'name' => ''],
            ['email' => ['email'], 'age' => ['integer'], 'name' => ['required']]
        )->errors()->all();

        $this->assertNotEmpty($messages);
        foreach ($messages as $message) {
            $this->assertDoesNotMatchRegularExpression('/\bfield\b/i', $message);
        }
    }
}
