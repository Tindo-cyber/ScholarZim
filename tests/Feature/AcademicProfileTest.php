<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\User;
use App\Services\ScholarFit\AcademicRecord;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\Gender;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The applicant's structured academic results, through the real form.
 *
 * The form is the half of this feature that did not exist: the controller
 * validated an `academic_subject_results` array no page ever posted, and the
 * profile rendered a free-text box instead. So results could only be created by
 * a seeder or a test, and - because the save path deleted everything and
 * recreated it from that always-empty array - any ordinary profile save wiped
 * whatever had been created.
 */
class AcademicProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private AcademicQualification $aLevel;

    private AcademicQualification $cambridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail();
        $this->aLevel = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $this->cambridge = AcademicQualification::findByKey(AcademicCatalogue::CAMBRIDGE_A_LEVEL);

        // The demo seeder gives this applicant three A-Level results. They are
        // fixtures for the local demo, not for these tests, and starting from
        // an empty record is what lets a rejection be asserted as "nothing was
        // written" rather than "nothing changed from three".
        $this->profile()->academicResults()->delete();
    }

    private function subject(AcademicQualification $qualification, string $name): AcademicSubject
    {
        return AcademicSubject::where('qualification_id', $qualification->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    /** The non-academic half of the profile form, as the page posts it. */
    private function baseFields(array $overrides = []): array
    {
        return array_merge([
            'full_name' => $this->student->full_name,
            'education_level' => EducationLevel::A_LEVEL,
            'institution_name' => 'Mutare Girls High School',
            'province' => 'Manicaland',
            'biography' => 'Aspiring doctor.',
        ], $overrides);
    }

    /** @param array<int, array{qualification: AcademicQualification, subject: string, result: string, year?: int}> $rows */
    private function academicFields(array $rows): array
    {
        $fields = ['academic_results_submitted' => '1'];

        foreach ($rows as $index => $row) {
            $fields['academic_subject_results'][$index] = array_filter([
                'qualification_id' => $row['qualification']->id,
                'subject_id' => $this->subject($row['qualification'], $row['subject'])->id,
                'result' => $row['result'],
                'year' => $row['year'] ?? null,
            ], static fn ($value) => $value !== null);
        }

        return $fields;
    }

    // ------------------------------------------------------------ the editor --

    public function test_an_applicant_records_subjects_and_the_platform_derives_the_points(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields() + $this->academicFields([
                ['qualification' => $this->aLevel, 'subject' => 'Mathematics', 'result' => 'A', 'year' => 2024],
                ['qualification' => $this->aLevel, 'subject' => 'Physics', 'result' => 'B', 'year' => 2024],
                ['qualification' => $this->aLevel, 'subject' => 'Chemistry', 'result' => 'A', 'year' => 2024],
            ]))
            ->assertRedirect(route('applicant.profile'))
            ->assertSessionHasNoErrors();

        $profile = $this->profile();

        $this->assertSame(3, $profile->academicResults()->count());
        $this->assertSame(14.0, $profile->zimsecALevelPoints(), 'A + B + A is 5 + 4 + 5');

        $mathematics = $profile->academicResults()
            ->where('subject_id', $this->subject($this->aLevel, 'Mathematics')->id)
            ->firstOrFail();

        $this->assertSame('A', $mathematics->result);
        $this->assertSame(5.0, $mathematics->points());
        $this->assertSame(2024, $mathematics->year);
    }

    /**
     * An applicant cannot post points. There is no `points` field in the
     * validated set, so one that arrives is discarded rather than trusted, and
     * the grade decides the value as it always should have.
     */
    public function test_a_points_value_posted_by_an_applicant_is_ignored(): void
    {
        $fields = $this->baseFields() + $this->academicFields([
            ['qualification' => $this->aLevel, 'subject' => 'Mathematics', 'result' => 'E'],
        ]);

        // An E is worth 1. Claiming 99 must change nothing.
        $fields['academic_subject_results'][0]['points'] = 99;
        $fields['academic_subject_results'][0]['derived_points'] = 99;

        $this->actingAs($this->student)->post('/applicant/profile', $fields)->assertSessionHasNoErrors();

        $this->assertSame(1.0, $this->profile()->zimsecALevelPoints());
    }

    public function test_a_grade_the_qualification_does_not_award_is_rejected(): void
    {
        // ZIMSEC A-Level has no A*; that is a Cambridge grade.
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields() + $this->academicFields([
                ['qualification' => $this->aLevel, 'subject' => 'Mathematics', 'result' => 'A*'],
            ]))
            ->assertSessionHasErrors('academic_subject_results.0.result');

        $this->assertSame(0, $this->profile()->academicResults()->count());
    }

    public function test_a_subject_from_another_qualification_is_rejected(): void
    {
        $cambridgeMaths = $this->subject($this->cambridge, 'Mathematics');

        $fields = $this->baseFields() + [
            'academic_results_submitted' => '1',
            'academic_subject_results' => [[
                // A ZIMSEC A-Level qualification paired with a Cambridge subject.
                'qualification_id' => $this->aLevel->id,
                'subject_id' => $cambridgeMaths->id,
                'result' => 'A',
            ]],
        ];

        $this->actingAs($this->student)
            ->post('/applicant/profile', $fields)
            ->assertSessionHasErrors('academic_subject_results.0.subject_id');

        $this->assertSame(0, $this->profile()->academicResults()->count());
    }

    public function test_the_same_subject_twice_under_one_qualification_is_rejected(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields() + $this->academicFields([
                ['qualification' => $this->aLevel, 'subject' => 'Mathematics', 'result' => 'A'],
                ['qualification' => $this->aLevel, 'subject' => 'Mathematics', 'result' => 'C'],
            ]))
            ->assertSessionHasErrors('academic_subject_results.1.subject_id');

        $this->assertSame(0, $this->profile()->academicResults()->count());
    }

    /** The database refuses a duplicate even if every layer above it were bypassed. */
    public function test_the_database_refuses_a_duplicate_result(): void
    {
        $profile = $this->profile();
        $subject = $this->subject($this->aLevel, 'Mathematics');

        AcademicResult::create([
            'profile_id' => $profile->profile_id,
            'qualification_id' => $this->aLevel->id,
            'subject_id' => $subject->id,
            'result' => 'A',
            'derived_points' => 5,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        AcademicResult::create([
            'profile_id' => $profile->profile_id,
            'qualification_id' => $this->aLevel->id,
            'subject_id' => $subject->id,
            'result' => 'C',
            'derived_points' => 3,
        ]);
    }

    public function test_an_applicant_can_edit_and_remove_results(): void
    {
        $this->actingAs($this->student)->post('/applicant/profile', $this->baseFields() + $this->academicFields([
            ['qualification' => $this->aLevel, 'subject' => 'Mathematics', 'result' => 'C'],
            ['qualification' => $this->aLevel, 'subject' => 'Physics', 'result' => 'D'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(5.0, $this->profile()->zimsecALevelPoints());

        // Mathematics upgraded to an A, Physics dropped entirely.
        $this->actingAs($this->student)->post('/applicant/profile', $this->baseFields() + $this->academicFields([
            ['qualification' => $this->aLevel, 'subject' => 'Mathematics', 'result' => 'A'],
        ]))->assertSessionHasNoErrors();

        $profile = $this->profile();

        $this->assertSame(1, $profile->academicResults()->count());
        $this->assertSame(5.0, $profile->zimsecALevelPoints());
    }

    /**
     * Existing rows are usable without JavaScript: their options are rendered
     * and selected server-side. If they were not, a save with scripting
     * unavailable would submit an empty grade for every row the applicant
     * already had, which is the data loss this feature was rebuilt to end.
     */
    public function test_saved_results_render_their_options_selected_server_side(): void
    {
        $this->actingAs($this->student)->post('/applicant/profile', $this->baseFields() + $this->academicFields([
            ['qualification' => $this->aLevel, 'subject' => 'Mathematics', 'result' => 'B'],
        ]))->assertSessionHasNoErrors();

        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();

        $this->assertStringContainsString('name="academic_subject_results[0][result]"', $html);
        $this->assertMatchesRegularExpression('/<option value="B"\s+selected>B<\/option>/', $html);

        $mathematicsId = $this->subject($this->aLevel, 'Mathematics')->id;
        $this->assertMatchesRegularExpression(
            '/<option value="'.$mathematicsId.'"\s+selected>Mathematics<\/option>/',
            $html
        );
    }

    // -------------------------------------------- the profile-save regression --

    /**
     * Mandatory regression. Saving an unrelated profile field must leave the
     * academic record exactly as it was.
     *
     * Before this, ProfileController called the sync unconditionally with
     * `$data['academic_subject_results'] ?? []`, and the service opened by
     * deleting every row - so editing a phone number destroyed an applicant's
     * results and silently changed what they were eligible for.
     */
    public function test_an_unrelated_profile_save_does_not_touch_academic_results(): void
    {
        $this->actingAs($this->student)->post('/applicant/profile', $this->baseFields() + $this->academicFields([
            ['qualification' => $this->aLevel, 'subject' => 'Mathematics', 'result' => 'A'],
            ['qualification' => $this->aLevel, 'subject' => 'Physics', 'result' => 'B'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(9.0, $this->profile()->zimsecALevelPoints());

        // A submission that carries no academic section at all - no marker, no
        // rows - exactly as a different form on the same route would post.
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields(['phone' => '+263 771 555 000']))
            ->assertSessionHasNoErrors();

        $profile = $this->profile();

        $this->assertSame(2, $profile->academicResults()->count(), 'results must survive an unrelated save');
        $this->assertSame(9.0, $profile->zimsecALevelPoints());
    }

    /** Removing the last row is still something an applicant is allowed to do. */
    public function test_submitting_the_editor_with_no_rows_clears_the_results(): void
    {
        $this->actingAs($this->student)->post('/applicant/profile', $this->baseFields() + $this->academicFields([
            ['qualification' => $this->aLevel, 'subject' => 'Mathematics', 'result' => 'A'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->profile()->academicResults()->count());

        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields() + ['academic_results_submitted' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->profile()->academicResults()->count());
    }

    // ------------------------------------------------------------- Cambridge --

    public function test_cambridge_results_are_stored_natively_and_earn_no_zimsec_points(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields() + $this->academicFields([
                ['qualification' => $this->cambridge, 'subject' => 'Mathematics', 'result' => 'A*'],
                ['qualification' => $this->cambridge, 'subject' => 'Physics', 'result' => 'A'],
            ]))
            ->assertSessionHasNoErrors();

        $profile = $this->profile();

        $this->assertSame(2, $profile->academicResults()->count());
        $this->assertNull($profile->zimsecALevelPoints(), 'Cambridge grades are not ZIMSEC A-Level points');

        foreach ($profile->academicResults as $result) {
            $this->assertNull($result->points());
        }
    }

    public function test_a_cambridge_as_grade_is_stored_in_its_own_lower_case(): void
    {
        $asLevel = AcademicQualification::findByKey(AcademicCatalogue::CAMBRIDGE_AS_LEVEL);

        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields() + $this->academicFields([
                // Posted upper case; Cambridge issues AS grades lower case.
                ['qualification' => $asLevel, 'subject' => 'Mathematics', 'result' => 'A'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('a', $this->profile()->academicResults()->firstOrFail()->result);
    }

    // ------------------------------------------------- Cambridge IGCSE 9-1 --

    public function test_an_applicant_can_record_a_nine_to_one_igcse_result(): void
    {
        $igcse = AcademicQualification::findByKey(AcademicCatalogue::CAMBRIDGE_IGCSE);

        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields() + $this->academicFields([
                ['qualification' => $igcse, 'subject' => 'Mathematics (9-1)', 'result' => '8'],
                ['qualification' => $igcse, 'subject' => 'Biology', 'result' => 'A'],
            ]))
            ->assertSessionHasNoErrors();

        $profile = $this->profile();

        $this->assertSame(2, $profile->academicResults()->count());
        $this->assertNull($profile->zimsecALevelPoints(), 'no IGCSE result earns ZIMSEC points');

        $results = $profile->academicResults()->with('subject')->get()
            ->mapWithKeys(fn ($r) => [$r->subject->name => $r->result]);

        // Both scales stored as issued, side by side under one qualification.
        $this->assertSame('8', $results['Mathematics (9-1)']);
        $this->assertSame('A', $results['Biology']);
    }

    public function test_a_letter_grade_is_refused_on_a_nine_to_one_syllabus(): void
    {
        $igcse = AcademicQualification::findByKey(AcademicCatalogue::CAMBRIDGE_IGCSE);

        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields() + $this->academicFields([
                ['qualification' => $igcse, 'subject' => 'Mathematics (9-1)', 'result' => 'B'],
            ]))
            ->assertSessionHasErrors('academic_subject_results.0.result');

        $this->assertSame(0, $this->profile()->academicResults()->count());
    }

    public function test_a_numeral_is_refused_on_an_a_star_to_g_syllabus(): void
    {
        $igcse = AcademicQualification::findByKey(AcademicCatalogue::CAMBRIDGE_IGCSE);

        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields() + $this->academicFields([
                // 0580 Mathematics is graded A*-G; a 7 is not a grade it awards.
                ['qualification' => $igcse, 'subject' => 'Mathematics', 'result' => '7'],
            ]))
            ->assertSessionHasErrors('academic_subject_results.0.result');

        $this->assertSame(0, $this->profile()->academicResults()->count());
    }

    /** The picker offers each syllabus only its own scale. */
    public function test_the_form_offers_each_igcse_syllabus_only_its_own_grades(): void
    {
        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();

        $igcse = AcademicQualification::findByKey(AcademicCatalogue::CAMBRIDGE_IGCSE);
        $catalogue = json_decode(
            (string) preg_replace('/.*id="academic-catalogue">(.*?)<\/script>.*/s', '$1', $html),
            true
        );

        $subjects = collect($catalogue[$igcse->id]['subjects'] ?? []);

        $aStarToG = $subjects->firstWhere('name', 'Mathematics (0580)');
        $nineToOne = $subjects->firstWhere('name', 'Mathematics (9-1) (0980)');

        $this->assertNotNull($aStarToG);
        $this->assertNotNull($nineToOne);

        // Null means "inherit the qualification's A*-G"; the 9-1 syllabus
        // carries its own list and never offers letters.
        $this->assertNull($aStarToG['grades']);
        $this->assertSame(['9', '8', '7', '6', '5', '4', '3', '2', '1', 'U'], $nineToOne['grades']);
    }

    // ---------------------------------------------------- legacy free text --

    /**
     * An applicant whose results predate the structured model is told to
     * re-enter them, and shown what they wrote before. Nothing is parsed and
     * nothing is filled in on their behalf.
     */
    public function test_an_applicant_with_only_legacy_results_is_asked_to_re_enter_them(): void
    {
        $profile = $this->profile();
        $profile->forceFill(['academic_results' => '13 points at A-Level (Biology A, Chemistry B, Maths B)'])->save();

        $this->assertTrue($profile->fresh()->needsAcademicResultsMigration());

        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();

        $this->assertStringContainsString('Please re-enter your results below', $html);
        $this->assertStringContainsString('13 points at A-Level (Biology A, Chemistry B, Maths B)', $html);

        // Shown back as a prompt, never parsed into results.
        $this->assertSame(0, $profile->fresh()->academicResults()->count());
        $this->assertNull($profile->fresh()->zimsecALevelPoints());
    }

    public function test_the_legacy_prompt_disappears_once_results_are_recorded(): void
    {
        $profile = $this->profile();
        $profile->forceFill(['academic_results' => '13 points at A-Level'])->save();

        $this->actingAs($this->student)->post('/applicant/profile', $this->baseFields() + $this->academicFields([
            ['qualification' => $this->aLevel, 'subject' => 'Mathematics', 'result' => 'B'],
        ]))->assertSessionHasNoErrors();

        $this->assertFalse($this->profile()->needsAcademicResultsMigration());

        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();
        $this->assertStringNotContainsString('Please re-enter your results below', $html);
    }

    /** Legacy text is not an academic fact, so it does not complete the profile. */
    public function test_legacy_text_alone_does_not_satisfy_profile_completeness(): void
    {
        $profile = $this->profile();
        $profile->forceFill(['academic_results' => '13 points at A-Level'])->save();

        $this->assertContains('Academic results', $profile->fresh()->missingFields());
    }

    /** And it is never read by the scoring engine. */
    public function test_legacy_text_contributes_nothing_to_scholarfit(): void
    {
        $profile = $this->profile();
        $profile->forceFill(['academic_results' => '18 points at A-Level'])->save();

        $record = AcademicRecord::fromProfile($profile->fresh());

        $this->assertFalse($record->isPresent());
        $this->assertNull($record->zimsecALevelPoints());
    }

    public function test_a_primary_applicant_can_record_several_learning_areas(): void
    {
        $primary = AcademicQualification::findByKey(AcademicCatalogue::ZIMBABWE_PRIMARY);
        $pupil = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();

        $fields = [
            'full_name' => $pupil->full_name,
            'education_level' => EducationLevel::PRIMARY,
            'institution_name' => 'Chitungwiza Primary',
            'province' => 'Harare',
            'guardian_name' => 'Rudo Marufu',
            'guardian_phone' => '+263 772 111 222',
            'guardian_relationship' => 'Mother',
            'academic_results_submitted' => '1',
            'academic_subject_results' => [
                ['qualification_id' => $primary->id, 'subject_id' => $this->subject($primary, 'Mathematics')->id, 'result' => '1'],
                ['qualification_id' => $primary->id, 'subject_id' => $this->subject($primary, 'English')->id, 'result' => '2'],
                ['qualification_id' => $primary->id, 'subject_id' => $this->subject($primary, 'Shona')->id, 'result' => '1'],
            ],
        ];

        $this->actingAs($pupil)->post('/applicant/profile', $fields)->assertSessionHasNoErrors();

        $profile = ApplicantProfile::where('user_id', $pupil->user_id)->firstOrFail();

        $this->assertSame(3, $profile->academicResults()->count());
        $this->assertNull($profile->zimsecALevelPoints(), 'Primary units are not A-Level points');

        foreach ($profile->academicResults as $result) {
            $this->assertNull($result->points());
        }
    }

    // ---------------------------------------------------------------- gender --

    public function test_an_applicant_can_record_their_gender(): void
    {
        foreach ([Gender::MALE, Gender::FEMALE] as $value) {
            $this->actingAs($this->student)
                ->post('/applicant/profile', $this->baseFields(['gender' => $value]))
                ->assertSessionHasNoErrors();

            $this->assertSame($value, $this->profile()->fresh()->gender);
        }
    }

    public function test_a_gender_outside_the_allowed_values_is_rejected(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields(['gender' => 'other']))
            ->assertSessionHasErrors('gender');

        $this->assertNull($this->profile()->gender);
    }

    /** Gender is captured, and deliberately has no bearing on matching. */
    public function test_gender_does_not_affect_profile_completeness(): void
    {
        $this->actingAs($this->student)
            ->post('/applicant/profile', $this->baseFields())
            ->assertSessionHasNoErrors();

        $labels = array_column($this->profile()->completionChecklist(), 'label');

        $this->assertNotContains('Gender', $labels);
    }

    // -------------------------------------------------------------- security --

    /** An applicant's results and gender are their own, keyed to the signed-in user. */
    public function test_an_applicant_cannot_write_to_another_applicants_profile(): void
    {
        $other = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $otherProfile = ApplicantProfile::where('user_id', $other->user_id)->firstOrFail();
        $otherResultsBefore = $otherProfile->academicResults()->count();

        // The route takes no profile id; posting one changes nothing, because
        // the service resolves the profile from the authenticated user.
        $this->actingAs($this->student)->post('/applicant/profile', $this->baseFields([
            'gender' => Gender::FEMALE,
            'profile_id' => $otherProfile->profile_id,
            'user_id' => $other->user_id,
        ]) + $this->academicFields([
            ['qualification' => $this->aLevel, 'subject' => 'Biology', 'result' => 'A'],
        ]))->assertSessionHasNoErrors();

        $otherProfile->refresh();

        $this->assertNull($otherProfile->gender);
        $this->assertSame($otherResultsBefore, $otherProfile->academicResults()->count());
        $this->assertSame(Gender::FEMALE, $this->profile()->gender);
        $this->assertSame(1, $this->profile()->academicResults()->count());
    }

    public function test_a_provider_cannot_reach_the_applicant_profile_form(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($provider)->get('/applicant/profile')->assertForbidden();
        $this->actingAs($provider)->post('/applicant/profile', $this->baseFields())->assertForbidden();
    }

    /**
     * The subject catalogue is reference data installed by migration. Nothing
     * in the application exposes a write route to it, for any role.
     */
    public function test_no_route_lets_a_user_modify_the_subject_catalogue(): void
    {
        $writable = collect(app('router')->getRoutes())
            ->filter(fn ($route) => ! in_array('GET', $route->methods(), true))
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_contains($uri, 'qualification') || str_contains($uri, 'academic-subject'))
            ->values();

        $this->assertCount(0, $writable, 'the academic catalogue must have no write endpoint');
    }
}
