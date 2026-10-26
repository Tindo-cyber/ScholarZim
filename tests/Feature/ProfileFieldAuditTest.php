<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Programme;
use App\Models\User;
use App\Support\Academic\AcademicCatalogue;
use App\Support\Deadlines;
use App\Support\EducationLevel;
use App\Support\FormOptions;
use App\Support\Gender;
use App\Services\ScholarFit\Taxonomy\SettlementType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PostsProfileForm;
use Tests\TestCase;

/**
 * Every field on My Profile, one rule at a time, through the page's own form and the real route.
 *
 * Each case says what is posted and which field must carry the error (or that it must save). A refused save is also checked for
 * the two things that matter beyond the message: no "Profile saved", and nothing written to the profile. Cases at a boundary come
 * in pairs - the last value that is accepted and the first that is not - so a rule cannot drift by one without a test noticing.
 */
class ProfileFieldAuditTest extends TestCase
{
    use PostsProfileForm;
    use RefreshDatabase;

    private User $adult;

    private User $schoolLeaver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake((string) config('filesystems.default', 'local'));

        // An undergraduate who already has an application; and an A-Level leaver with saved results.
        $this->adult = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->schoolLeaver = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
    }

    private function as(User $user): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }

    private function dob(int $years): string
    {
        return Deadlines::today()->subYears($years)->toDateString();
    }

    /** The saved value of a profile column, or of the account for the two held there. */
    private function stored(User $user, string $field): mixed
    {
        return in_array($field, ['full_name', 'phone'], true) ? $user->fresh()->{$field} : $this->profileOf($user)->{$field};
    }

    private function assertRefusedAndUnchanged(User $user, array $changes, string $errorOn): void
    {
        $field = array_key_first($changes);
        $before = $this->stored($user, $field);

        $this->saveProfile($user, $changes)->assertSessionHasErrors($errorOn)->assertSessionMissing('successMessage');

        $this->assertEquals($before, $this->stored($user, $field), "$field was not written by a refused save");
    }

    // ============================================================= personal information --

    public static function fullNames(): array
    {
        return [
            'empty' => ['', false], 'only spaces' => ['   ', false], 'one letter' => ['A', true], 'at the limit' => [str_repeat('a', 255), true],
            'over the limit' => [str_repeat('a', 256), false], 'with digits' => ['Tendai 7', false], 'markup' => ['<b>Tendai</b>', false],
            'apostrophe hyphen and full stop' => ["O'Brien-Smith Jr.", true], 'accented letters' => ['Zoë Ndlovu', true], 'symbols only' => ['!!!', false],
        ];
    }

    #[DataProvider('fullNames')]
    public function test_full_name(string $value, bool $accepted): void
    {
        if ($accepted) {
            $this->saveProfile($this->adult, ['full_name' => $value])->assertSessionHasNoErrors()->assertSessionHas('successMessage');
            $this->assertSame(trim($value), $this->adult->fresh()->full_name);
        } else {
            $this->assertRefusedAndUnchanged($this->adult, ['full_name' => $value], 'full_name');
        }
    }

    public static function phones(): array
    {
        return [
            'empty (optional)' => ['', true], 'ten digits' => ['0771234567', true], 'nine digits' => ['077123456', false], 'eleven digits' => ['07712345678', false],
            'with a country code' => ['+263771234567', false], 'with letters' => ['07712A4567', false], 'only letters' => ['abcdefghij', false],
            'with spaces inside' => ['077 123 4567', false], 'with dashes' => ['077-123-4567', false], 'with a line break' => ["0771234567\n9", false],
        ];
    }

    #[DataProvider('phones')]
    public function test_phone_number(string $value, bool $accepted): void
    {
        if ($accepted) {
            $this->saveProfile($this->adult, ['phone' => $value])->assertSessionHasNoErrors()->assertSessionHas('successMessage');
            $this->assertSame($value === '' ? null : $value, $this->adult->fresh()->phone);
        } else {
            $this->assertRefusedAndUnchanged($this->adult, ['phone' => $value], 'phone');
        }
    }

    public static function datesOfBirth(): array
    {
        return [
            'empty (an adult need not say)' => [fn () => '', true], 'a plain adult date' => [fn () => '1995-05-05', true], 'in the future' => [fn () => Deadlines::today()->addDay()->toDateString(), false],
            'today' => [fn () => Deadlines::today()->toDateString(), false], 'eleven years old' => [fn () => Deadlines::today()->subYears(11)->toDateString(), false],
            'the day before 1920' => [fn () => '1919-12-31', false], 'a day that does not exist' => [fn () => '2000-02-30', false], 'the wrong format' => [fn () => '05/05/2000', false],
            'words' => [fn () => 'last tuesday', false], 'an array' => [fn () => ['2000-01-01'], false],
        ];
    }

    #[DataProvider('datesOfBirth')]
    public function test_date_of_birth(callable $value, bool $accepted): void
    {
        if ($accepted) {
            $this->saveProfile($this->adult, ['date_of_birth' => $value()])->assertSessionHasNoErrors()->assertSessionHas('successMessage');
        } else {
            $this->assertRefusedAndUnchanged($this->adult, ['date_of_birth' => $value()], 'date_of_birth');
        }
    }

    public function test_the_youngest_age_accepted_is_twelve_with_a_guardian(): void
    {
        $guardian = ['guardian_name' => 'Grace Moyo', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother', 'guardian_confirmed' => '1'];

        $this->saveProfile($this->schoolLeaver, $guardian + ['date_of_birth' => $this->dob(12)])->assertSessionHasNoErrors();
        $this->assertRefusedAndUnchanged($this->schoolLeaver, $guardian + ['date_of_birth' => $this->dob(11)], 'date_of_birth');
    }

    public static function genders(): array
    {
        return ['empty' => ['', false], 'not on the list' => ['robot', false], 'an array' => [['male'], false], 'female' => ['female', true], 'male' => ['male', true]];
    }

    #[DataProvider('genders')]
    public function test_gender_is_required_and_from_the_list(mixed $value, bool $accepted): void
    {
        if ($accepted) {
            $this->saveProfile($this->adult, ['gender' => $value])->assertSessionHasNoErrors();
            $this->assertSame($value, $this->profileOf($this->adult)->gender);
        } else {
            $this->assertRefusedAndUnchanged($this->adult, ['gender' => $value], 'gender');
        }
    }

    public function test_every_gender_on_the_list_is_accepted(): void
    {
        foreach (Gender::ALL as $gender) {
            $this->saveProfile($this->adult, ['gender' => $gender])->assertSessionHasNoErrors();
        }
    }

    // ================================================================ study and location --

    public static function institutions(): array
    {
        return [
            'empty' => ['', true], 'a normal name' => ["St. Mary's High School", true], 'a name with brackets' => ['University of Zimbabwe (UZ)', true],
            'at the limit' => [str_repeat('i', 255), true], 'over the limit' => [str_repeat('i', 256), false], 'only digits' => ['12345', false], 'only symbols' => ['***', false],
        ];
    }

    #[DataProvider('institutions')]
    public function test_institution(string $value, bool $accepted): void
    {
        if ($accepted) {
            $this->saveProfile($this->adult, ['institution_name' => $value])->assertSessionHasNoErrors();
            $this->assertSame($value === '' ? null : $value, $this->profileOf($this->adult)->institution_name);
        } else {
            $this->assertRefusedAndUnchanged($this->adult, ['institution_name' => $value], 'institution_name');
        }
    }

    public static function provinces(): array
    {
        return ['empty' => ['', true], 'a real province' => ['Bulawayo', true], 'not a province' => ['Atlantis', false], 'a district name' => ['Gweru', false], 'wrong case' => ['bulawayo', false]];
    }

    #[DataProvider('provinces')]
    public function test_province(string $value, bool $accepted): void
    {
        if ($accepted) {
            $this->saveProfile($this->adult, ['province' => $value, 'locality' => ''])->assertSessionHasNoErrors();
        } else {
            $this->assertRefusedAndUnchanged($this->adult, ['province' => $value], 'province');
        }
    }

    public function test_every_province_on_the_list_is_accepted(): void
    {
        foreach (FormOptions::ZIMBABWE_PROVINCES as $province) {
            $this->saveProfile($this->adult, ['province' => $province, 'locality' => ''])->assertSessionHasNoErrors();
            $this->assertSame($province, $this->profileOf($this->adult)->province);
        }
    }

    public static function localities(): array
    {
        return [
            'empty' => ['Midlands', '', true], 'a town in its province' => ['Midlands', 'Gweru', true], 'at the limit' => ['Harare', str_repeat('l', 100), true],
            'over the limit' => ['Harare', str_repeat('l', 101), false], 'a town in another province' => ['Harare', 'Gweru', false], 'only digits' => ['Harare', '12345', false],
        ];
    }

    #[DataProvider('localities')]
    public function test_town_or_city(string $province, string $value, bool $accepted): void
    {
        if ($accepted) {
            $this->saveProfile($this->adult, ['province' => $province, 'locality' => $value])->assertSessionHasNoErrors();
            $this->assertSame($value === '' ? null : $value, $this->profileOf($this->adult)->locality);
        } else {
            $this->assertRefusedAndUnchanged($this->adult, ['province' => $province, 'locality' => $value], 'locality');
        }
    }

    public static function homeAreas(): array
    {
        return ['empty' => ['', true], 'not on the list' => ['moon', false], 'an array' => [['URBAN'], false]];
    }

    #[DataProvider('homeAreas')]
    public function test_home_area(mixed $value, bool $accepted): void
    {
        if ($accepted) {
            $this->saveProfile($this->adult, ['settlement_type' => $value])->assertSessionHasNoErrors();
        } else {
            $this->assertRefusedAndUnchanged($this->adult, ['settlement_type' => $value], 'settlement_type');
        }
    }

    public function test_every_home_area_on_the_list_is_accepted(): void
    {
        foreach (SettlementType::ALL as $type) {
            $this->saveProfile($this->adult, ['settlement_type' => $type])->assertSessionHasNoErrors();
            $this->assertSame($type, $this->profileOf($this->adult)->settlement_type);
        }
    }

    public static function biographies(): array
    {
        return ['empty' => ['', true], 'a sentence' => ['I want to study engineering.', true], 'at the limit' => [str_repeat('b', 5000), true], 'over the limit' => [str_repeat('b', 5001), false]];
    }

    #[DataProvider('biographies')]
    public function test_biography(string $value, bool $accepted): void
    {
        if ($accepted) {
            $this->saveProfile($this->adult, ['biography' => $value])->assertSessionHasNoErrors();
        } else {
            $this->assertRefusedAndUnchanged($this->adult, ['biography' => $value], 'biography');
        }
    }

    // ===================================================================== education level --

    public function test_every_applicant_level_is_accepted_and_nothing_else_is(): void
    {
        foreach (EducationLevel::APPLICANT_LEVELS as $level) {
            // An adult, so the level alone is what is being tested; a level they have not earned the step to is a separate rule.
            $this->saveProfile($this->adult, ['education_level' => $level, 'date_of_birth' => $this->dob(30)]);
            $this->assertStringNotContainsString('is not valid', (string) (session('errors')?->first('education_level')), $level);
        }

        foreach (['WIZARD', 'FORM_1', 'primary school', ['A_LEVEL']] as $bad) {
            $this->assertRefusedAndUnchanged($this->adult, ['education_level' => $bad], 'education_level');
        }
    }

    public function test_a_degree_classification_must_be_one_the_scheme_awards(): void
    {
        $this->assertRefusedAndUnchanged($this->adult, ['degree_classification' => 'Magic Pass'], 'degree_classification');

        $valid = AcademicCatalogue::scheme(AcademicCatalogue::TERTIARY)->symbols()[0];
        $this->saveProfile($this->adult, ['degree_classification' => $valid])->assertSessionHasNoErrors();
        $this->assertSame($valid, $this->profileOf($this->adult)->degree_classification);
    }

    // ===================================================================== programme --

    public function test_the_programme_must_be_a_real_one_for_the_level(): void
    {
        $before = $this->profileOf($this->adult)->field_of_study;

        foreach (['abc', '1.5', '999999', '-1', '0'] as $bad) {
            $response = $this->saveProfile($this->adult, ['current_programme_id' => $bad]);
            $this->assertLessThan(500, $response->getStatusCode(), $bad);
            $response->assertSessionMissing('successMessage');
        }

        $this->assertSame($before, $this->profileOf($this->adult)->field_of_study, 'a refused programme changes nothing');

        $programme = Programme::where('education_level', EducationLevel::UNDERGRADUATE)->firstOrFail();
        $this->saveProfile($this->adult, ['current_programme_id' => (string) $programme->id])->assertSessionHasNoErrors()->assertSessionHas('successMessage');
        $this->assertSame($programme->name, $this->profileOf($this->adult)->fresh()->field_of_study);
    }

    // ======================================================================= academic results --

    private function zimsec(): AcademicQualification
    {
        return AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
    }

    private function subject(string $name, ?AcademicQualification $q = null): AcademicSubject
    {
        return AcademicSubject::where('qualification_id', ($q ?? $this->zimsec())->id)->where('name', $name)->firstOrFail();
    }

    private function row(AcademicSubject $s, string $grade, mixed $year = 2024, ?AcademicQualification $q = null): array
    {
        return ['qualification_id' => ($q ?? $this->zimsec())->id, 'subject_id' => $s->id, 'result' => $grade, 'year' => $year];
    }

    private function saveResults(array $rows)
    {
        return $this->saveProfile($this->schoolLeaver, ['academic_results_submitted' => '1', 'academic_subject_results' => $rows]);
    }

    private function savedResults(): array
    {
        return AcademicResult::where('profile_id', $this->profileOf($this->schoolLeaver)->profile_id)->with('subject')->get()
            ->map(fn ($r) => $r->subject->name . '/' . $r->result . '/' . $r->year)->sort()->values()->all();
    }

    public function test_valid_results_are_saved_with_their_points(): void
    {
        $this->saveResults([$this->row($this->subject('Mathematics'), 'A'), $this->row($this->subject('Physics'), 'B')])->assertSessionHasNoErrors();

        $this->assertSame(['Mathematics/A/2024', 'Physics/B/2024'], $this->savedResults());
        $this->assertNotNull(AcademicResult::where('profile_id', $this->profileOf($this->schoolLeaver)->profile_id)->value('derived_points'));
    }

    public static function badResultRows(): array
    {
        return [
            'a grade the board does not award' => [fn ($t) => $t->row($t->subject('Mathematics'), 'Z9'), 'academic_subject_results.0.result'],
            'a grade from another scale' => [fn ($t) => $t->row($t->subject('Mathematics'), '7'), 'academic_subject_results.0.result'],
            'no grade' => [fn ($t) => $t->row($t->subject('Mathematics'), ''), 'academic_subject_results.0.result'],
            'a grade that is too long' => [fn ($t) => $t->row($t->subject('Mathematics'), str_repeat('A', 21)), 'academic_subject_results.0.result'],
            'a subject that does not exist' => [fn ($t) => ['qualification_id' => $t->zimsec()->id, 'subject_id' => 999999, 'result' => 'A', 'year' => 2024], 'academic_subject_results.0.subject_id'],
            'a subject from another qualification' => [fn ($t) => ['qualification_id' => $t->zimsec()->id, 'subject_id' => AcademicSubject::where('qualification_id', AcademicQualification::findByKey('zimsec-o-level')->id)->value('id'), 'result' => 'A', 'year' => 2024], 'academic_subject_results.0.subject_id'],
            'no subject' => [fn ($t) => ['qualification_id' => $t->zimsec()->id, 'subject_id' => '', 'result' => 'A', 'year' => 2024], 'academic_subject_results.0.subject_id'],
            'a qualification that does not exist' => [fn ($t) => ['qualification_id' => 999999, 'subject_id' => 1, 'result' => 'A', 'year' => 2024], 'academic_subject_results.0.qualification_id'],
            'no qualification' => [fn ($t) => ['qualification_id' => '', 'subject_id' => 1, 'result' => 'A', 'year' => 2024], 'academic_subject_results.0.qualification_id'],
            'a year before 1950' => [fn ($t) => $t->row($t->subject('Mathematics'), 'A', 1949), 'academic_subject_results.0.year'],
            'a year two years ahead' => [fn ($t) => $t->row($t->subject('Mathematics'), 'A', (int) date('Y') + 2), 'academic_subject_results.0.year'],
            'a year that is text' => [fn ($t) => $t->row($t->subject('Mathematics'), 'A', 'abcd'), 'academic_subject_results.0.year'],
            'a year with a decimal' => [fn ($t) => $t->row($t->subject('Mathematics'), 'A', '2020.5'), 'academic_subject_results.0.year'],
        ];
    }

    #[DataProvider('badResultRows')]
    public function test_a_bad_result_row_is_refused_and_leaves_the_saved_results_alone(callable $row, string $errorOn): void
    {
        $this->saveResults([$this->row($this->subject('Mathematics'), 'A'), $this->row($this->subject('Physics'), 'B')])->assertSessionHasNoErrors();
        $before = $this->savedResults();

        $this->saveResults([$row($this)])->assertSessionHasErrors($errorOn)->assertSessionMissing('successMessage');

        $this->assertSame($before, $this->savedResults());
    }

    public function test_the_year_limits_are_1950_and_next_year(): void
    {
        $this->saveResults([$this->row($this->subject('Mathematics'), 'A', 1950)])->assertSessionHasNoErrors();
        $this->saveResults([$this->row($this->subject('Mathematics'), 'A', (int) date('Y') + 1)])->assertSessionHasNoErrors();
        $this->saveResults([$this->row($this->subject('Mathematics'), 'A', '')])->assertSessionHasNoErrors();
    }

    public function test_an_inactive_subject_cannot_be_recorded(): void
    {
        $subject = $this->subject('Shona');
        $subject->update(['is_active' => false]);

        $this->saveResults([$this->row($subject, 'A')])->assertSessionHasErrors('academic_subject_results.0.subject_id');
    }

    public function test_forty_rows_are_the_most_one_save_takes(): void
    {
        $this->saveResults(array_fill(0, 41, $this->row($this->subject('Mathematics'), 'A')))->assertSessionHasErrors('academic_subject_results');
    }

    public function test_a_save_that_does_not_carry_the_results_section_leaves_them_alone_and_removing_every_row_is_a_deletion(): void
    {
        $this->saveResults([$this->row($this->subject('Mathematics'), 'A')])->assertSessionHasNoErrors();
        $this->as($this->schoolLeaver)->post('/applicant/profile', ['full_name' => $this->schoolLeaver->full_name, 'gender' => 'male', 'education_level' => EducationLevel::A_LEVEL, 'date_of_birth' => $this->dob(25)])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Mathematics/A/2024'], $this->savedResults(), 'a request without the marker does not touch results');

        $this->saveResults([])->assertSessionHasNoErrors();
        $this->assertSame([], $this->savedResults(), 'the marker with no rows is the applicant removing them all');
    }

    public function test_a_row_with_the_wrong_shape_is_refused_not_a_server_error(): void
    {
        foreach (['text', 5, [['qualification_id' => ['x'], 'subject_id' => ['y'], 'result' => ['z'], 'year' => ['w']]]] as $rows) {
            $response = $this->saveProfile($this->schoolLeaver, ['academic_results_submitted' => '1', 'academic_subject_results' => $rows]);

            $this->assertLessThan(500, $response->getStatusCode(), json_encode($rows));
            $response->assertSessionMissing('successMessage');
        }
    }

    public function test_replacing_one_subject_with_another_persists_after_a_reload(): void
    {
        $this->saveResults([$this->row($this->subject('Mathematics'), 'C'), $this->row($this->subject('Physics'), 'B')])->assertSessionHasNoErrors();

        $this->saveResults([$this->row($this->subject('Shona'), 'C'), $this->row($this->subject('Physics'), 'B')])->assertSessionHasNoErrors();

        $this->assertSame(['Physics/B/2024', 'Shona/C/2024'], $this->savedResults());
        $page = $this->as($this->schoolLeaver)->get('/applicant/profile')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-selected="' . $this->subject('Shona')->id . '"#', $page);
        $this->assertDoesNotMatchRegularExpression('#data-selected="' . $this->subject('Mathematics')->id . '"#', $page);
    }

    // ================================================== a refused save keeps what was typed --

    public function test_a_refused_save_keeps_every_other_value_in_the_form(): void
    {
        $this->saveProfile($this->adult, ['biography' => 'Typed and kept.', 'institution_name' => 'Kept College', 'phone' => 'not-a-phone'])
            ->assertSessionHasErrors('phone');

        // The same session carries what was typed back to the form: no new sign-in, no flush.
        $page = $this->get('/applicant/profile')->assertOk()->getContent();

        $this->assertStringContainsString('Typed and kept.', $page);
        $this->assertStringContainsString('Kept College', $page);
        $this->assertStringContainsString('not-a-phone', $page);
        $this->assertSame('Computer Science graduate building civic-tech projects for rural schools.', $this->profileOf($this->adult)->biography, 'while nothing was saved');
    }

    public function test_several_mistakes_are_all_reported_at_once_on_their_own_fields(): void
    {
        $this->saveProfile($this->adult, ['full_name' => 'Tendai 7', 'phone' => 'abc', 'date_of_birth' => '2999-01-01', 'gender' => 'robot', 'province' => 'Atlantis', 'biography' => str_repeat('b', 5001)])
            ->assertSessionHasErrors(['full_name', 'phone', 'date_of_birth', 'gender', 'province', 'biography']);
    }

    // ============================================================================= documents --

    private function upload(User $user, string $type, mixed $file)
    {
        return $this->as($user)->post(route('applicant.profile.documents', $type), ['document' => $file]);
    }

    private function realFile(string $name, string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pf');
        file_put_contents($path, $body);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdf(string $name = 'doc.pdf'): UploadedFile
    {
        return $this->realFile($name, "%PDF-1.4\n% $name\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    public static function acceptedDocumentNames(): array
    {
        return ['a pdf' => ['letter.pdf'], 'a jpg' => ['scan.jpg'], 'a jpeg' => ['scan.jpeg'], 'a png' => ['scan.png']];
    }

    public function test_a_good_document_is_accepted_for_every_required_type_at_the_level(): void
    {
        foreach ($this->profileOf($this->adult)->documentChecklist()->items as $item) {
            $this->upload($this->adult, $item['type'], $this->pdf($item['type'] . '.pdf'))->assertSessionHasNoErrors()->assertSessionHas('successMessage');
            $this->assertTrue($this->profileOf($this->adult)->hasDocument($item['type']), $item['type'] . ' is on file');
        }
    }

    public static function refusedUploads(): array
    {
        return [
            'a script' => ['evil.php', '<?php echo 1; ?>'],
            'an executable' => ['setup.exe', 'MZ not a document'],
            'html' => ['page.html', '<html><script>alert(1)</script></html>'],
            'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'a gif' => ['anim.gif', "GIF89a\x01\x00\x01\x00"],
            'a script renamed to pdf' => ['cv.pdf', '<?php echo 1; ?>'],
            'an empty file' => ['empty.pdf', ''],
        ];
    }

    #[DataProvider('refusedUploads')]
    public function test_an_unsafe_or_empty_document_is_refused_and_stores_nothing(string $name, string $body): void
    {
        $before = $this->profileOf($this->adult)->transcript_path;

        $response = $this->upload($this->adult, 'transcript', $this->realFile($name, $body));

        $response->assertSessionMissing('successMessage');
        $this->assertTrue(session('errors') !== null || session('errorMessage') !== null, 'the applicant is told why');
        $this->assertSame($before, $this->profileOf($this->adult)->transcript_path);
        $this->assertSame([], array_values(array_filter(Storage::disk((string) config('filesystems.default', 'local'))->allFiles(), fn ($f) => str_contains($f, 'profiles/' . $this->adult->user_id))));
    }

    public function test_the_size_limit_is_five_megabytes(): void
    {
        $this->upload($this->adult, 'transcript', UploadedFile::fake()->create('ok.pdf', 5120, 'application/pdf'))->assertSessionMissing('errors');
        $this->upload($this->adult, 'transcript', UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf'))->assertSessionHasErrors('document');
    }

    public function test_a_missing_file_and_a_list_of_files_are_refused(): void
    {
        $this->as($this->adult)->post(route('applicant.profile.documents', 'transcript'), [])->assertSessionHasErrors('document');
        $this->as($this->adult)->post(route('applicant.profile.documents', 'transcript'), ['document' => [$this->pdf(), $this->pdf()]])->assertSessionHasErrors('document');
        $this->as($this->adult)->post(route('applicant.profile.documents', 'transcript'), ['document' => 'not-a-file'])->assertSessionHasErrors('document');
    }

    public function test_a_document_type_that_does_not_exist_is_a_404_and_the_slip_is_for_pupils_only(): void
    {
        $this->upload($this->adult, 'selfie', $this->pdf())->assertNotFound();

        $this->upload($this->adult, 'grade7_slip', $this->pdf())->assertSessionHasErrors('document');

        $pupil = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $this->upload($pupil, 'transcript', $this->pdf())->assertSessionHasErrors('document');
        $this->upload($pupil, 'grade7_slip', $this->pdf())->assertSessionHasNoErrors()->assertSessionHas('successMessage');
    }

    // ========================================================= the profile is the applicant's own --

    public function test_only_an_applicant_can_save_or_upload_and_a_visitor_is_sent_to_sign_in(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->as($provider)->post('/applicant/profile', ['full_name' => 'X Y'])->assertForbidden();
        $this->as($provider)->post(route('applicant.profile.documents', 'cv'), ['document' => $this->pdf()])->assertForbidden();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post('/applicant/profile', ['full_name' => 'X Y'])->assertRedirect('/login');
    }

    public function test_another_applicants_profile_cannot_be_reached_by_adding_an_id(): void
    {
        $before = $this->profileOf($this->schoolLeaver)->only(['institution_name', 'biography']);

        $this->saveProfile($this->adult, ['user_id' => $this->schoolLeaver->user_id, 'profile_id' => $this->profileOf($this->schoolLeaver)->profile_id, 'institution_name' => 'Mine Only'])
            ->assertSessionHasNoErrors();

        $this->assertSame($before, $this->profileOf($this->schoolLeaver)->only(['institution_name', 'biography']));
        $this->assertSame('Mine Only', $this->profileOf($this->adult)->institution_name);
    }
}
