<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\Institution;
use App\Models\Programme;
use App\Models\User;
use App\Services\ProfileDataQuality;
use App\Support\EducationLevel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Which programme are you on - or hoping to be on?" Enrolled students name their current
 * programme; school-leavers (O-Level, A-Level) name up to three they hope to study; a Primary
 * pupil has none.
 */
class ApplicantProgrammesTest extends TestCase
{
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

    private function level(string $level): void
    {
        $this->profile()->forceFill(['education_level' => $level])->save();
    }

    private function programme(string $name): int
    {
        return Programme::where('name', $name)->value('id');
    }

    private function field(string $code): int
    {
        return Field::where('code', $code)->value('id');
    }

    private function save(array $data)
    {
        return $this->actingAs($this->student)->post('/applicant/profile/programmes', $data);
    }

    private function rows(?string $kind = null)
    {
        return ApplicantProgramme::where('profile_id', $this->profile()->profile_id)->when($kind, fn ($q) => $q->where('kind', $kind))->get();
    }

    // -------------------------------------------------------- enrolled students --

    public function test_an_enrolled_student_names_their_current_programme_and_institution(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);

        $this->save([
            'current_programme_id' => $this->programme('BSc Information Systems'),
            'current_institution_id' => Institution::where('code', 'MSU')->value('id'),
        ])->assertSessionHasNoErrors();

        $current = $this->rows('current')->sole();
        $this->assertSame($this->programme('BSc Information Systems'), $current->programme_id);
        $this->assertSame(Institution::where('code', 'MSU')->value('id'), $current->institution_id);
    }

    public function test_choosing_another_replaces_it_and_choosing_none_clears_it(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);
        $this->save(['current_programme_id' => $this->programme('BSc Information Systems')]);

        $this->save(['current_programme_id' => $this->programme('BSc Computer Science')]);
        $this->assertSame($this->programme('BSc Computer Science'), $this->rows('current')->sole()->programme_id);

        $this->save([]);
        $this->assertCount(0, $this->rows());
    }

    public function test_the_programme_must_be_at_the_students_level(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);

        $this->save(['current_programme_id' => $this->programme('Diploma in Information Technology')])
            ->assertSessionHasErrors('current_programme_id');

        $this->assertCount(0, $this->rows());
    }

    public function test_a_programme_that_does_not_exist_or_an_unknown_institution_is_refused(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);

        $this->save(['current_programme_id' => 999999])->assertSessionHasErrors('current_programme_id');
        $this->save(['current_programme_id' => $this->programme('BSc Computer Science'), 'current_institution_id' => 999999])
            ->assertSessionHasErrors('current_institution_id');
    }

    public function test_an_enrolled_student_cannot_list_intended_programmes(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);

        $this->save(['intended_programme_ids' => [$this->programme('BSc Computer Science')]])
            ->assertSessionHasErrors('intended_programme_ids');
    }

    // ------------------------------------------------------------ school-leavers --

    public function test_a_school_leaver_names_up_to_three_programmes_they_hope_to_study(): void
    {
        foreach ([EducationLevel::A_LEVEL, EducationLevel::O_LEVEL] as $level) {
            $this->level($level);
            ApplicantProgramme::query()->delete();

            $this->save(['intended_programme_ids' => [
                $this->programme('BSc Computer Science'),
                $this->programme('Diploma in Information Technology'),
                $this->programme('Bachelor of Laws'),
            ]])->assertSessionHasNoErrors();

            $this->assertCount(3, $this->rows('intended'), $level);
        }
    }

    public function test_four_is_too_many(): void
    {
        $this->level(EducationLevel::A_LEVEL);

        $this->save(['intended_programme_ids' => [
            $this->programme('BSc Computer Science'), $this->programme('Bachelor of Laws'),
            $this->programme('BSc Physics'), $this->programme('BSc Chemistry'),
        ]])->assertSessionHasErrors('intended_programme_ids');

        $this->assertCount(0, $this->rows());
    }

    public function test_the_same_programme_twice_counts_once(): void
    {
        $this->level(EducationLevel::A_LEVEL);
        $id = $this->programme('BSc Computer Science');

        $this->save(['intended_programme_ids' => [$id, $id]])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->rows());
    }

    public function test_a_school_leaver_can_only_name_programmes_a_school_leaver_enters(): void
    {
        $this->level(EducationLevel::A_LEVEL);

        foreach (['Master of Business Administration', 'PhD in Education', 'Postgraduate Diploma in Education'] as $name) {
            $this->save(['intended_programme_ids' => [$this->programme($name)]])
                ->assertSessionHasErrors('intended_programme_ids');
        }

        $this->assertCount(0, $this->rows());
    }

    public function test_a_school_leaver_has_no_current_programme(): void
    {
        $this->level(EducationLevel::A_LEVEL);

        $this->save(['current_programme_id' => $this->programme('BSc Computer Science')])
            ->assertSessionHasErrors('current_programme_id');
    }

    public function test_saving_replaces_the_whole_list(): void
    {
        $this->level(EducationLevel::A_LEVEL);
        $this->save(['intended_programme_ids' => [$this->programme('BSc Computer Science'), $this->programme('Bachelor of Laws')]]);

        $this->save(['intended_programme_ids' => [$this->programme('BSc Physics')]]);

        $this->assertSame([$this->programme('BSc Physics')], $this->rows('intended')->pluck('programme_id')->all());
    }

    // ------------------------------------------------------------------ primary --

    public function test_a_primary_pupil_has_no_programme(): void
    {
        $this->level(EducationLevel::PRIMARY);

        $this->save(['intended_programme_ids' => [$this->programme('BSc Computer Science')]])->assertSessionHasErrors();

        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="programme-card"', $html);
    }

    // -------------------------------------------------------------- not listed --

    public function test_an_enrolled_student_can_add_a_programme_that_is_not_listed(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);

        $this->save([
            'programme_suggestion' => 'BSc Quantum Basketweaving',
            'programme_suggestion_field' => $this->field('053'),
        ])->assertSessionHasNoErrors();

        $programme = Programme::where('name', 'BSc Quantum Basketweaving')->firstOrFail();
        $this->assertTrue($programme->isPending());
        $this->assertSame(EducationLevel::UNDERGRADUATE, $programme->education_level, 'their own level');
        $this->assertSame($programme->id, $this->rows('current')->sole()->programme_id);
    }

    public function test_a_school_leaver_adding_one_chooses_its_level_from_the_ones_they_enter(): void
    {
        $this->level(EducationLevel::A_LEVEL);

        $this->save([
            'programme_suggestion' => 'Diploma in Quantum Basketweaving',
            'programme_suggestion_field' => $this->field('053'),
            'programme_suggestion_level' => EducationLevel::DIPLOMA,
        ])->assertSessionHasNoErrors();

        $this->assertSame(EducationLevel::DIPLOMA, Programme::where('name', 'Diploma in Quantum Basketweaving')->value('education_level'));

        $this->save([
            'programme_suggestion' => 'Masters in Wishful Thinking',
            'programme_suggestion_field' => $this->field('053'),
            'programme_suggestion_level' => EducationLevel::MASTERS,
        ])->assertSessionHasErrors('programme_suggestion_level');
    }

    public function test_the_suggestion_needs_a_field(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);

        $this->save(['programme_suggestion' => 'BSc Quantum Basketweaving'])->assertSessionHasErrors('programme_suggestion_field');
    }

    public function test_someone_elses_pending_programme_cannot_be_chosen(): void
    {
        $other = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $theirs = app(\App\Services\Catalogue\ProgrammeCatalogue::class)
            ->suggest($other, 'Their Odd Studies', EducationLevel::UNDERGRADUATE, $this->field('053'));
        $this->level(EducationLevel::UNDERGRADUATE);

        $this->save(['current_programme_id' => $theirs->id])->assertSessionHasErrors('current_programme_id');
    }

    // --------------------------------------------------------------------- page --

    public function test_the_profile_page_offers_programmes_at_the_students_level(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);

        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();

        $this->assertStringContainsString('id="programme-card"', $html);
        $this->assertStringContainsString('BSc Information Systems', $html);
        $this->assertStringNotContainsString('Diploma in Information Technology', $html);
        $this->assertMatchesRegularExpression('#data-search="[^"]*info systems#i', $html);
    }

    public function test_a_school_leavers_page_offers_the_levels_they_enter_and_says_up_to_three(): void
    {
        $this->level(EducationLevel::A_LEVEL);

        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();

        $this->assertStringContainsString('Diploma in Information Technology', $html);
        $this->assertStringContainsString('BSc Information Systems', $html);
        $this->assertStringNotContainsString('Master of Business Administration', $html);
        $this->assertStringContainsString('up to 3', $html);
    }

    public function test_the_current_choice_is_shown_selected(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);
        $this->save(['current_programme_id' => $this->programme('BSc Computer Science')]);

        $html = $this->actingAs($this->student)->get('/applicant/profile')->getContent();

        $this->assertMatchesRegularExpression('#<option value="' . $this->programme('BSc Computer Science') . '"[^>]*selected#', $html);
    }

    public function test_a_provider_cannot_use_the_route(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($provider)->post('/applicant/profile/programmes', [])->assertForbidden();
    }

    // ----------------------------------------------------------- data quality --

    public function test_a_programme_at_another_level_than_the_profile_is_flagged(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);
        $this->save(['current_programme_id' => $this->programme('BSc Computer Science')]);

        $this->level(EducationLevel::MASTERS);
        $warnings = ProfileDataQuality::warnings($this->profile()->fresh());

        $this->assertContains(ProfileDataQuality::PROGRAMME, array_column($warnings, 'code'));
    }

    public function test_a_programme_at_the_right_level_is_not_flagged(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);
        $this->save(['current_programme_id' => $this->programme('BSc Computer Science')]);

        $this->assertNotContains(ProfileDataQuality::PROGRAMME, array_column(ProfileDataQuality::warnings($this->profile()->fresh()), 'code'));
    }
}
