<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\User;
use App\Services\ScholarFit\AcademicRecord;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PostsProfileForm;
use Tests\TestCase;

/**
 * The duplicate-result rule at the school levels other than A-Level: Primary and O-Level.
 *
 * The same subject cannot be recorded twice for one sitting - within one qualification (the table's own key; a re-sit replaces
 * the grade) or under two boards at the same level (ZIMSEC O Level, Cambridge O Level and Cambridge IGCSE are all O-Level) -
 * unless each is its own sitting with its own year. Each case goes through the real profile form and reads the database.
 */
class DuplicateResultsAllLevelsTest extends TestCase
{
    use PostsProfileForm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake((string) config('filesystems.default', 'local'));
    }

    private function as(User $user): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }

    private function applicant(string $level): User
    {
        return User::where('email', $level === 'primary' ? 'kudzai.marufu@scholarzim.co.zw' : 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
    }

    private function qualification(string $key): AcademicQualification
    {
        return AcademicQualification::findByKey($key);
    }

    private function subject(AcademicQualification $q, string $name): AcademicSubject
    {
        return AcademicSubject::where('qualification_id', $q->id)->where('name', $name)->firstOrFail();
    }

    private function row(AcademicQualification $q, string $subject, string $grade, ?int $year = 2024): array
    {
        return ['qualification_id' => $q->id, 'subject_id' => $this->subject($q, $subject)->id, 'result' => $grade, 'year' => $year];
    }

    /** What each board calls Mathematics (it is one subject, written three ways). */
    private function mathsRow(string $boardKey, string $grade, ?int $year = 2024): array
    {
        $name = ['zimsec-o-level' => 'Mathematics', 'cambridge-o-level' => 'Mathematics (Syllabus D)', 'cambridge-igcse' => 'Mathematics'][$boardKey];

        return $this->row($this->qualification($boardKey), $name, $grade, $year);
    }

    private function save(User $user, array $rows)
    {
        return $this->saveProfile($user, ['academic_results_submitted' => '1', 'academic_subject_results' => $rows]);
    }

    /** @return array<int, string> "qualification:subject:grade:year" for every saved result, sorted */
    private function saved(User $user): array
    {
        return AcademicResult::where('profile_id', $this->profileOf($user)->profile_id)->with('subject', 'qualification')->get()
            ->map(fn ($r) => $r->qualification->qualification_key . ':' . $r->subject->name . ':' . $r->result . ':' . $r->year)->sort()->values()->all();
    }

    private function mathematicsRows(User $user): int
    {
        return count(array_filter($this->saved($user), fn ($r) => preg_match('/:Mathematics( \(|:)/', $r) === 1));
    }

    // ================================================================ Primary --

    public function test_primary_the_same_subject_twice_in_one_submission_is_refused(): void
    {
        $pupil = $this->applicant('primary');
        $primary = $this->qualification('zimbabwe-primary');
        $before = $this->saved($pupil);

        $this->save($pupil, [$this->row($primary, 'Mathematics', '2', 2025), $this->row($primary, 'Mathematics', '3', 2025)])
            ->assertSessionHasErrors('academic_subject_results.1.subject_id')->assertSessionMissing('successMessage');

        $this->assertSame($before, $this->saved($pupil), 'a refused save changes nothing');
    }

    public function test_primary_a_second_save_with_the_same_subject_replaces_it_instead_of_adding_one(): void
    {
        $pupil = $this->applicant('primary');
        $primary = $this->qualification('zimbabwe-primary');

        $this->save($pupil, [$this->row($primary, 'Mathematics', '3', 2025)])->assertSessionHasNoErrors();
        $id = AcademicResult::where('profile_id', $this->profileOf($pupil)->profile_id)->where('subject_id', $this->subject($primary, 'Mathematics')->id)->value('id');

        $this->save($pupil, [$this->row($primary, 'Mathematics', '1', 2025)])->assertSessionHasNoErrors();

        $this->assertSame('1', AcademicResult::findOrFail($id)->result, 'the same record was updated');
        $this->assertSame(1, count($this->saved($pupil)));
    }

    public function test_primary_a_subject_can_be_replaced_by_another_and_the_old_one_goes(): void
    {
        $pupil = $this->applicant('primary');
        $primary = $this->qualification('zimbabwe-primary');

        $this->save($pupil, [$this->row($primary, 'Mathematics', '2'), $this->row($primary, 'English', '2')])->assertSessionHasNoErrors();
        $this->save($pupil, [$this->row($primary, 'Shona', '2'), $this->row($primary, 'English', '2')])->assertSessionHasNoErrors();

        $this->assertSame(['zimbabwe-primary:English:2:2024', 'zimbabwe-primary:Shona:2:2024'], $this->saved($pupil));
    }

    public function test_primary_the_database_refuses_a_second_row_for_one_subject(): void
    {
        $pupil = $this->applicant('primary');
        $primary = $this->qualification('zimbabwe-primary');
        $profile = $this->profileOf($pupil);
        AcademicResult::where('profile_id', $profile->profile_id)->delete();
        $subject = $this->subject($primary, 'Mathematics');

        AcademicResult::create(['profile_id' => $profile->profile_id, 'qualification_id' => $primary->id, 'subject_id' => $subject->id, 'result' => '2', 'year' => 2025]);

        $this->expectException(UniqueConstraintViolationException::class);
        AcademicResult::create(['profile_id' => $profile->profile_id, 'qualification_id' => $primary->id, 'subject_id' => $subject->id, 'result' => '3', 'year' => 2024]);
    }

    // ================================================================= O-Level --

    public static function oLevelBoards(): array
    {
        return [
            'ZIMSEC O Level and Cambridge O Level' => ['zimsec-o-level', 'cambridge-o-level'],
            'ZIMSEC O Level and Cambridge IGCSE' => ['zimsec-o-level', 'cambridge-igcse'],
            'Cambridge O Level and Cambridge IGCSE' => ['cambridge-o-level', 'cambridge-igcse'],
        ];
    }

    public function test_o_level_the_same_subject_twice_in_one_qualification_is_refused(): void
    {
        $farai = $this->applicant('o');
        $zimsec = $this->qualification('zimsec-o-level');
        $before = $this->saved($farai);

        $this->save($farai, [$this->row($zimsec, 'Mathematics', 'B'), $this->row($zimsec, 'Mathematics', 'C')])
            ->assertSessionHasErrors('academic_subject_results.1.subject_id')->assertSessionMissing('successMessage');

        $this->assertSame($before, $this->saved($farai));
    }

    #[DataProvider('oLevelBoards')]
    public function test_o_level_the_same_subject_under_two_boards_for_one_sitting_is_a_duplicate(string $first, string $second): void
    {
        $farai = $this->applicant('o');
        $before = $this->saved($farai);

        foreach ([[2025, 2025], [null, null], [2025, null]] as [$yearA, $yearB]) {
            $this->save($farai, [$this->mathsRow($first, 'B', $yearA), $this->mathsRow($second, 'B', $yearB)])
                ->assertSessionHasErrors('academic_subject_results.1.subject_id')->assertSessionMissing('successMessage');
        }

        $this->assertSame($before, $this->saved($farai), 'none of the refused saves wrote anything');
        $this->assertStringContainsString('already recorded under', (string) session('errors')->first('academic_subject_results.1.subject_id'));
    }

    #[DataProvider('oLevelBoards')]
    public function test_o_level_a_genuine_resit_with_another_board_in_another_year_is_accepted(string $first, string $second): void
    {
        $farai = $this->applicant('o');

        $this->save($farai, [$this->mathsRow($first, 'D', 2024), $this->mathsRow($second, 'B', 2025)])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->mathematicsRows($farai));
    }

    public function test_o_level_a_duplicate_against_a_result_already_saved_is_refused_and_the_saved_one_survives(): void
    {
        $farai = $this->applicant('o');
        $zimsec = $this->qualification('zimsec-o-level');

        $this->save($farai, [$this->row($zimsec, 'Mathematics', 'B', 2025)])->assertSessionHasNoErrors();
        $saved = $this->saved($farai);

        $this->save($farai, [$this->row($zimsec, 'Mathematics', 'B', 2025), $this->mathsRow('cambridge-o-level', 'A', 2025)])
            ->assertSessionHasErrors('academic_subject_results.1.subject_id');

        $this->assertSame($saved, $this->saved($farai));
    }

    public function test_o_level_editing_a_result_updates_the_same_record_and_a_different_subject_is_not_a_duplicate(): void
    {
        $farai = $this->applicant('o');
        $zimsec = $this->qualification('zimsec-o-level');

        $this->save($farai, [$this->row($zimsec, 'Mathematics', 'C'), $this->row($zimsec, 'English Language', 'B')])->assertSessionHasNoErrors();
        $id = AcademicResult::where('profile_id', $this->profileOf($farai)->profile_id)->where('subject_id', $this->subject($zimsec, 'Mathematics')->id)->value('id');

        $this->save($farai, [$this->row($zimsec, 'Mathematics', 'A'), $this->row($zimsec, 'English Language', 'B')])->assertSessionHasNoErrors();

        $this->assertSame('A', AcademicResult::findOrFail($id)->result);
        $this->assertSame(2, count($this->saved($farai)));
    }

    public function test_o_level_one_subject_is_never_held_twice_for_scholarfit_to_count(): void
    {
        $farai = $this->applicant('o');
        $zimsec = $this->qualification('zimsec-o-level');

        $this->save($farai, [$this->row($zimsec, 'Mathematics', 'B', 2025)])->assertSessionHasNoErrors();
        $this->save($farai, [$this->row($zimsec, 'Mathematics', 'B', 2025), $this->mathsRow('cambridge-igcse', 'A', 2025)])->assertSessionHasErrors();

        $this->assertSame(1, $this->mathematicsRows($farai));
        $record = AcademicRecord::fromProfile($this->profileOf($farai)->fresh());
        $this->assertSame(1, $record->subjectCount(), 'ScholarFit reads the one result that was kept');
    }
    public function test_o_level_the_two_igcse_syllabuses_of_one_subject_are_one_subject(): void
    {
        $farai = $this->applicant('o');
        $igcse = $this->qualification('cambridge-igcse');
        $before = $this->saved($farai);

        // "Mathematics" (A*-G) and "Mathematics (9-1)" are two syllabuses of the same subject: one sitting cannot hold both.
        $this->save($farai, [$this->row($igcse, 'Mathematics', 'B', 2025), $this->row($igcse, 'Mathematics (9-1)', '6', 2025)])
            ->assertSessionHasErrors('academic_subject_results.1.subject_id');

        $this->assertSame($before, $this->saved($farai));

        // ...but in different years they are two sittings.
        $this->save($farai, [$this->row($igcse, 'Mathematics', 'B', 2024), $this->row($igcse, 'Mathematics (9-1)', '6', 2025)])->assertSessionHasNoErrors();
    }

    public function test_o_level_a_different_subject_with_a_similar_name_is_not_a_duplicate(): void
    {
        $farai = $this->applicant('o');
        $zimsec = $this->qualification('zimsec-o-level');

        $this->save($farai, [$this->row($zimsec, 'Mathematics', 'B', 2025), $this->row($zimsec, 'Additional Mathematics', 'C', 2025)])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, count($this->saved($farai)));
    }

    public function test_one_subject_recorded_for_primary_does_not_clash_with_the_same_name_at_another_level(): void
    {
        // Mathematics at Primary and Mathematics at O-Level are results at different levels, never one subject twice.
        $pupil = $this->applicant('primary');
        $primary = $this->qualification('zimbabwe-primary');

        $this->save($pupil, [$this->row($primary, 'Mathematics', '2', 2025), $this->row($primary, 'English', '2', 2025)])->assertSessionHasNoErrors();
        $this->assertSame(2, count($this->saved($pupil)));
    }
}
