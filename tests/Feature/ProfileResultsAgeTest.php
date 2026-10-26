<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicantProfileService;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\Academic\AcademicCatalogue;
use App\Support\ApplicationStatus;
use App\Support\Deadlines;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Three defects on the applicant profile:
 *
 *  1. A saved profile change did not stick: clearing the phone number reported "Profile saved" and the old number came
 *     back. (No rule about applications ever stopped a profile being saved - proved below for an applicant who has one.)
 *  2. The same A-Level subject could be recorded once for each board (ZIMSEC A Level, Cambridge AS, Cambridge A Level).
 *  3. A date of birth was judged against the qualification: an adult with an A-Level was told theirs "does not look right".
 *
 * Every case goes through the real route and then reads the database, so "saved" means saved.
 */
class ProfileResultsAgeTest extends TestCase
{
    use RefreshDatabase;

    private User $withApplication;

    private User $withoutApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake((string) config('filesystems.default', 'local'));

        $this->withApplication = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->withoutApplication = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------------------ helpers --

    private function as(User $user): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }

    private function profile(User $user): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
    }

    /** What the profile page would post as it stands: every pre-filled field, so a test changes only what it means to. */
    private function formFields(User $user): array
    {
        $html = $this->as($user)->get('/applicant/profile')->assertOk()->getContent();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $pairs = [];

        foreach ($xpath->query('.//input|.//select|.//textarea', $xpath->query('//form[@id="sz-profile-form"]')->item(0)) as $element) {
            $name = $element->getAttribute('name');
            $type = strtolower($element->getAttribute('type'));

            if ($name === '' || $element->hasAttribute('disabled') || in_array($type, ['submit', 'button', 'file'], true)
                || (in_array($type, ['checkbox', 'radio'], true) && ! $element->hasAttribute('checked'))) {
                continue;
            }

            if ($element->tagName === 'select') {
                $value = '';
                foreach ($xpath->query('.//option', $element) as $option) {
                    if ($option->hasAttribute('selected')) {
                        $value = $option->getAttribute('value');
                    }
                }
            } else {
                $value = $element->tagName === 'textarea' ? $element->textContent : $element->getAttribute('value');
            }

            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        // Back into the nested array the browser's own encoding would arrive as (academic_subject_results[0][result] and so on).
        parse_str(implode('&', $pairs), $fields);

        return $fields;
    }

    private function save(User $user, array $changes = [])
    {
        return $this->as($user)->post('/applicant/profile', $changes + $this->formFields($user));
    }

    // =============================================================== 1. profile saves --

    public static function applicants(): array
    {
        return ['an applicant with an application' => ['withApplication', 1], 'an applicant with none' => ['withoutApplication', 0]];
    }

    #[DataProvider('applicants')]
    public function test_saved_changes_persist_and_show_after_reloading(string $who, int $applications): void
    {
        $user = $this->{$who};
        $this->assertSame($applications, Application::where('user_id', $user->user_id)->count(), 'the fixture is what the test says it is');

        $this->save($user, ['institution_name' => 'A Different College', 'biography' => 'Written afresh.', 'gender' => 'female'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('successMessage');

        $saved = $this->profile($user);
        $this->assertSame('A Different College', $saved->institution_name);
        $this->assertSame('Written afresh.', $saved->biography);
        $this->assertSame('female', $saved->gender);

        // Reloaded: the page itself shows what was saved.
        $page = $this->as($user)->get('/applicant/profile')->assertOk()->getContent();
        $this->assertStringContainsString('A Different College', $page);
        $this->assertStringContainsString('Written afresh.', $page);
    }

    #[DataProvider('applicants')]
    public function test_a_cleared_phone_number_stays_cleared(string $who): void
    {
        $user = $this->{$who};

        $this->save($user, ['phone' => '0771112233'])->assertSessionHasNoErrors();
        $this->assertSame('0771112233', $user->fresh()->phone);

        // The bug: this said "Profile saved" and the old number came back.
        $this->save($user, ['phone' => ''])->assertSessionHasNoErrors()->assertSessionHas('successMessage');
        $this->assertNull($user->fresh()->phone);

        $this->assertStringNotContainsString('0771112233', $this->as($user)->get('/applicant/profile')->getContent());
    }

    public function test_a_partial_update_that_does_not_mention_the_phone_leaves_it_alone(): void
    {
        $this->withApplication->update(['phone' => '0771112233']);

        app(ApplicantProfileService::class)->update($this->withApplication, ['biography' => 'Only this changed.']);

        $this->assertSame('0771112233', $this->withApplication->fresh()->phone);
        $this->assertSame('Only this changed.', $this->profile($this->withApplication)->biography);
    }

    public function test_a_refused_save_shows_no_success_and_saves_nothing(): void
    {
        $before = $this->profile($this->withApplication)->only(['institution_name', 'biography', 'province']);

        $this->save($this->withApplication, ['institution_name' => 'Should Not Stick', 'province' => 'Atlantis'])
            ->assertSessionHasErrors('province')
            ->assertSessionMissing('successMessage');

        $this->assertSame($before, $this->profile($this->withApplication)->only(['institution_name', 'biography', 'province']));
    }

    public function test_changing_the_profile_does_not_rewrite_a_submitted_application(): void
    {
        $application = Application::where('user_id', $this->withApplication->user_id)->firstOrFail();
        $snapshot = ['profile' => ['education_level' => EducationLevel::UNDERGRADUATE, 'institution_name' => 'University of Zimbabwe (UZ)', 'age' => 23], 'eligibility' => ['verdict' => 'eligible']];
        $application->update(['submitted_snapshot' => $snapshot, 'personal_statement' => 'As submitted.']);

        $this->save($this->withApplication, ['institution_name' => 'A Different College', 'biography' => 'Changed later.', 'date_of_birth' => '1990-01-01'])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame($snapshot, $application->submitted_snapshot, 'the snapshot is what was sent, whatever the profile says now');
        $this->assertSame('As submitted.', $application->personal_statement);
        $this->assertSame(ApplicationStatus::PENDING, ApplicationStatus::canonical($application->application_status));
    }

    // ============================================================ 2. duplicate results --

    private function alevel(): array
    {
        $zimsec = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $cambridgeA = AcademicQualification::findByKey('cambridge-a-level');
        $cambridgeAs = AcademicQualification::findByKey('cambridge-as-level');
        $maths = fn (AcademicQualification $q) => AcademicSubject::where('qualification_id', $q->id)->where('name', 'Mathematics')->firstOrFail();

        return [$zimsec, $cambridgeA, $cambridgeAs, $maths($zimsec), $maths($cambridgeA), $maths($cambridgeAs)];
    }

    private function row(AcademicQualification $q, AcademicSubject $s, string $grade, ?int $year = 2024): array
    {
        return ['qualification_id' => $q->id, 'subject_id' => $s->id, 'result' => $grade, 'year' => $year];
    }

    private function saveResults(array $rows)
    {
        return $this->save($this->withoutApplication, ['academic_results_submitted' => '1', 'academic_subject_results' => $rows]);
    }

    /** This applicant's own results (other seeded applicants hold the same subjects). */
    private function mine(): \Illuminate\Database\Eloquent\Builder
    {
        return AcademicResult::query()->where('profile_id', $this->profile($this->withoutApplication)->profile_id);
    }

    private function resultCount(): int
    {
        return AcademicResult::where('profile_id', $this->profile($this->withoutApplication)->profile_id)->count();
    }

    public function test_the_same_subject_twice_in_one_submission_is_refused_and_nothing_is_saved(): void
    {
        [$zimsec, , , $mathsZ] = $this->alevel();
        $before = $this->resultCount();

        $this->saveResults([$this->row($zimsec, $mathsZ, 'A'), $this->row($zimsec, $mathsZ, 'B')])
            ->assertSessionHasErrors('academic_subject_results.1.subject_id');

        $this->assertSame($before, $this->resultCount());
    }

    public static function sameSittingCases(): array
    {
        return [
            'the same year' => [2024, 2024],
            'no year on either' => [null, null],
            'a year on only one' => [2024, null],
        ];
    }

    #[DataProvider('sameSittingCases')]
    public function test_the_same_subject_under_two_boards_for_one_sitting_is_a_duplicate(?int $first, ?int $second): void
    {
        [$zimsec, $cambridgeA, , $mathsZ, $mathsC] = $this->alevel();
        $before = $this->resultCount();

        $this->saveResults([$this->row($zimsec, $mathsZ, 'A', $first), $this->row($cambridgeA, $mathsC, 'B', $second)])
            ->assertSessionHasErrors('academic_subject_results.1.subject_id');

        $this->assertSame($before, $this->resultCount(), 'a refused submission saves none of it');
        $this->assertStringContainsString('already recorded under ZIMSEC Advanced Level', (string) session('errors')->first('academic_subject_results.1.subject_id'));
    }

    public function test_a_genuine_resit_with_another_board_in_another_year_is_accepted(): void
    {
        [$zimsec, $cambridgeA, , $mathsZ, $mathsC] = $this->alevel();

        $this->saveResults([$this->row($zimsec, $mathsZ, 'C', 2023), $this->row($cambridgeA, $mathsC, 'A', 2024)])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, AcademicResult::where('profile_id', $this->profile($this->withoutApplication)->profile_id)
            ->whereIn('subject_id', [$mathsZ->id, $mathsC->id])->count());
    }

    public function test_an_as_then_a_level_in_different_years_is_accepted_but_not_in_the_same_year(): void
    {
        [, $cambridgeA, $cambridgeAs, , $mathsC, $mathsAs] = $this->alevel();

        $this->saveResults([$this->row($cambridgeAs, $mathsAs, 'b', 2023), $this->row($cambridgeA, $mathsC, 'A', 2024)])->assertSessionHasNoErrors();
        $this->saveResults([$this->row($cambridgeAs, $mathsAs, 'b', 2024), $this->row($cambridgeA, $mathsC, 'A', 2024)])
            ->assertSessionHasErrors('academic_subject_results.1.subject_id');
    }

    public function test_a_duplicate_against_a_result_already_saved_is_refused_and_the_saved_one_survives(): void
    {
        [$zimsec, $cambridgeA, , $mathsZ, $mathsC] = $this->alevel();

        $this->saveResults([$this->row($zimsec, $mathsZ, 'A', 2024)])->assertSessionHasNoErrors();
        $savedId = $this->mine()->where('subject_id', $mathsZ->id)->value('id');

        // A second, separate submission that keeps the saved result and adds the same subject under another board.
        $this->saveResults([$this->row($zimsec, $mathsZ, 'A', 2024), $this->row($cambridgeA, $mathsC, 'B', 2024)])
            ->assertSessionHasErrors('academic_subject_results.1.subject_id');

        $this->assertSame($savedId, $this->mine()->where('subject_id', $mathsZ->id)->value('id'));
        $this->assertSame(0, $this->mine()->where('subject_id', $mathsC->id)->count());
    }

    public function test_editing_a_result_updates_that_record_instead_of_adding_another(): void
    {
        [$zimsec, , , $mathsZ] = $this->alevel();

        $this->saveResults([$this->row($zimsec, $mathsZ, 'C', 2023)])->assertSessionHasNoErrors();
        $id = $this->mine()->where('subject_id', $mathsZ->id)->value('id');
        $count = $this->resultCount();

        // A corrected grade, then a re-sit in the same qualification: both replace the one row for that subject.
        $this->saveResults([$this->row($zimsec, $mathsZ, 'B', 2023)])->assertSessionHasNoErrors();
        $this->saveResults([$this->row($zimsec, $mathsZ, 'A', 2024)])->assertSessionHasNoErrors();

        $row = AcademicResult::findOrFail($id);
        $this->assertSame('A', $row->result);
        $this->assertSame(2024, $row->year);
        $this->assertSame($count, $this->resultCount());
    }

    public function test_the_database_itself_refuses_a_second_row_for_one_qualification_and_subject(): void
    {
        [$zimsec, , , $mathsZ] = $this->alevel();
        $profile = $this->profile($this->withoutApplication);
        AcademicResult::query()->where('profile_id', $profile->profile_id)->delete();

        AcademicResult::create(['profile_id' => $profile->profile_id, 'qualification_id' => $zimsec->id, 'subject_id' => $mathsZ->id, 'result' => 'A', 'year' => 2024]);

        $this->expectException(UniqueConstraintViolationException::class);
        AcademicResult::create(['profile_id' => $profile->profile_id, 'qualification_id' => $zimsec->id, 'subject_id' => $mathsZ->id, 'result' => 'B', 'year' => 2023]);
    }

    public function test_a_refused_duplicate_cannot_inflate_the_points_scholarfit_reads(): void
    {
        [$zimsec, $cambridgeA, , $mathsZ, $mathsC] = $this->alevel();

        $this->saveResults([$this->row($zimsec, $mathsZ, 'A', 2024)])->assertSessionHasNoErrors();
        $once = AcademicRecord::fromProfile($this->profile($this->withoutApplication)->fresh())->zimsecALevelPoints();

        $this->saveResults([$this->row($zimsec, $mathsZ, 'A', 2024), $this->row($zimsec, $mathsZ, 'A', 2024), $this->row($cambridgeA, $mathsC, 'A', 2024)])
            ->assertSessionHasErrors();

        $this->assertSame($once, AcademicRecord::fromProfile($this->profile($this->withoutApplication)->fresh())->zimsecALevelPoints());
        $this->assertNotNull($once);
    }

    // ================================================================ 3. age rules --

    private function alevelDob(int $years, string $level = EducationLevel::A_LEVEL)
    {
        // A guardian's details travel with it: someone under 18 cannot save without them, and an adult's request ignores them.
        return $this->save($this->withoutApplication, [
            'education_level' => $level, 'date_of_birth' => Deadlines::today()->subYears($years)->subDays(3)->toDateString(),
            'guardian_name' => 'Grace Moyo', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother', 'guardian_confirmed' => '1',
        ]);
    }

    public static function ages(): array
    {
        return ['16' => [16], '17' => [17], '19' => [19], '25' => [25], '26' => [26], '32' => [32], '45' => [45], '60' => [60]];
    }

    #[DataProvider('ages')]
    public function test_an_applicant_of_any_age_can_hold_an_a_level(int $years): void
    {
        $profile = $this->profile($this->withoutApplication);
        $held = AcademicResult::where('profile_id', $profile->profile_id)->count();

        $this->alevelDob($years)->assertSessionHasNoErrors()->assertSessionHas('successMessage');

        $saved = $this->profile($this->withoutApplication);
        $this->assertSame(EducationLevel::A_LEVEL, $saved->education_level);
        $this->assertSame($years, $saved->age());
        $this->assertSame($held, AcademicResult::where('profile_id', $saved->profile_id)->count(), 'their results were not touched by their age');
        $this->assertNull(EducationLevel::ageConsistencyReason(EducationLevel::A_LEVEL, $years));
        $this->assertNull(EducationLevel::ageConsistencyReason(EducationLevel::O_LEVEL, $years));
    }

    public static function badDates(): array
    {
        return [
            'tomorrow' => [fn () => Deadlines::today()->addDay()->toDateString()],
            'next year' => [fn () => Deadlines::today()->addYear()->toDateString()],
            'today' => [fn () => Deadlines::today()->toDateString()],
            'a day that does not exist' => [fn () => '2001-02-30'],
            'not a date' => [fn () => 'last tuesday'],
            'wrong format' => [fn () => '05/05/2001'],
            'before 1920' => [fn () => '1919-12-31'],
            'an array' => [fn () => ['2001-01-01']],
            'under the platform minimum' => [fn () => Deadlines::today()->subYears(11)->toDateString()],
        ];
    }

    #[DataProvider('badDates')]
    public function test_a_date_of_birth_that_is_impossible_is_refused_and_nothing_changes(callable $date): void
    {
        $before = $this->profile($this->withoutApplication)->date_of_birth?->toDateString();

        $this->save($this->withoutApplication, ['date_of_birth' => $date()])->assertSessionHasErrors('date_of_birth');

        $this->assertSame($before, $this->profile($this->withoutApplication)->date_of_birth?->toDateString());
    }

    public function test_one_calculation_gives_the_same_age_everywhere(): void
    {
        $born = Carbon::parse('2000-06-15');

        $this->assertSame(26, ApplicantProfile::ageBetween($born, Carbon::parse('2026-06-15')), 'on the birthday it counts');
        $this->assertSame(25, ApplicantProfile::ageBetween($born, Carbon::parse('2026-06-14')), 'the day before it does not');
        $this->assertSame(26, ApplicantProfile::ageBetween($born, Carbon::parse('2026-06-16')));

        $profile = new ApplicantProfile(['date_of_birth' => Deadlines::today()->subYears(30)->subDay()->toDateString()]);
        $this->assertSame(30, $profile->age());
        $this->assertSame($profile->age(), $profile->ageOn(Deadlines::today()));
    }

    // ------------------------------------------------- scholarship age limits and ScholarFit --

    private function listing(array $attributes = []): Opportunity
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        return Opportunity::create(array_merge([
            'provider_user_id' => $provider->user_id, 'provider_name' => 'Age Provider', 'title' => 'Age Award ' . uniqid(),
            'description' => 'Used by the age tests.', 'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'award_slots' => 3, 'deadline' => Carbon::today()->addDays(60), 'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => now()->subDay(), 'created_at' => now(),
        ], $attributes));
    }

    private function ageOutcome(User $user, Opportunity $listing): ?RequirementOutcome
    {
        $profile = $this->profile($user)->fresh();
        $outcomes = app(EligibilityEvaluator::class)->evaluate($profile, $listing, AcademicRecord::fromProfile($profile));

        return collect($outcomes)->first(fn (RequirementOutcome $o) => $o->type === RequirementOutcome::TYPE_AGE);
    }

    public function test_scholarfit_checks_age_only_for_a_listing_that_states_an_age_limit(): void
    {
        $profile = $this->profile($this->withoutApplication);
        $profile->update(['date_of_birth' => Deadlines::today()->subYears(40)->toDateString()]);

        $this->assertNull($this->ageOutcome($this->withoutApplication, $this->listing()), 'no age limit stated: age is not looked at');
        $this->assertNull($this->ageOutcome($this->withoutApplication, $this->listing(['max_age' => null])));

        // Even for somebody with no date of birth at all.
        $profile->update(['date_of_birth' => null]);
        $this->assertNull($this->ageOutcome($this->withoutApplication, $this->listing()));
    }

    public function test_a_listing_with_a_maximum_age_judges_the_age_at_its_deadline(): void
    {
        $listing = $this->listing(['max_age' => 25, 'deadline' => Carbon::today()->addDays(30)]);
        $profile = $this->profile($this->withoutApplication);

        $profile->update(['date_of_birth' => Deadlines::today()->subYears(24)->toDateString()]);
        $this->assertTrue($this->ageOutcome($this->withoutApplication, $listing)->passed);

        $profile->update(['date_of_birth' => Deadlines::today()->subYears(25)->toDateString()]);
        $this->assertTrue($this->ageOutcome($this->withoutApplication, $listing)->passed, '25 is within "25 and under"');

        $profile->update(['date_of_birth' => Deadlines::today()->subYears(30)->toDateString()]);
        $this->assertFalse($this->ageOutcome($this->withoutApplication, $listing)->passed);

        $profile->update(['date_of_birth' => null]);
        $this->assertTrue($this->ageOutcome($this->withoutApplication, $listing)->needsInformation, 'no date of birth is a question, not a refusal');
    }

    private function listingForm(array $over = []): array
    {
        return array_merge([
            'title' => 'Age Form Award', 'description' => 'Posted by the age tests.', 'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship', 'deadline' => Carbon::today()->addDays(30)->toDateString(), 'award_slots' => 2,
        ], $over);
    }

    public static function badMaximumAges(): array
    {
        return ['negative' => ['-5'], 'zero' => ['0'], 'below the platform minimum' => ['11'], 'a decimal' => ['25.5'], 'text' => ['young'],
            'an exponent' => ['1e2'], 'over 99' => ['100'], 'an array' => [['25']]];
    }

    #[DataProvider('badMaximumAges')]
    public function test_an_impossible_maximum_age_is_refused_when_creating_and_editing(mixed $age): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->as($provider)->post('/opportunities/create', $this->listingForm(['max_age' => $age]))->assertSessionHasErrors('max_age');
        $this->assertDatabaseMissing('opportunities', ['title' => 'Age Form Award']);

        $listing = $this->listing(['max_age' => 30, 'education_level' => EducationLevel::UNDERGRADUATE]);
        $this->as($provider)->put('/opportunities/' . $listing->opportunity_id, $this->listingForm(['title' => $listing->title, 'max_age' => $age, 'reason' => 'Testing']))
            ->assertSessionHasErrors('max_age');
        $this->assertSame(30, $listing->fresh()->max_age, 'a refused edit changes nothing');
    }

    public function test_a_valid_maximum_age_is_saved_and_a_blank_one_is_not_invented(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->as($provider)->post('/opportunities/create', $this->listingForm(['title' => 'With an age', 'max_age' => '25']))->assertSessionHasNoErrors();
        $this->assertSame(25, Opportunity::where('title', 'With an age')->value('max_age'));

        foreach (['', null] as $i => $blank) {
            $this->as($provider)->post('/opportunities/create', $this->listingForm(['title' => 'Without an age ' . $i, 'max_age' => $blank]))->assertSessionHasNoErrors();
            $this->assertNull(Opportunity::where('title', 'Without an age ' . $i)->value('max_age'), 'no age limit was asked for, so none exists');
        }

        // Left out of the form altogether.
        $payload = $this->listingForm(['title' => 'Age left out']);
        unset($payload['max_age']);
        $this->as($provider)->post('/opportunities/create', $payload)->assertSessionHasNoErrors();
        $this->assertNull(Opportunity::where('title', 'Age left out')->value('max_age'));
    }

    public function test_the_listing_form_follows_the_age_limit_in_the_browser_too(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $html = $this->as($provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<input[^>]*name="max_age"[^>]*>#', $html);
        $this->assertMatchesRegularExpression('#<input[^>]*name="max_age"[^>]*min="\d+"|<input[^>]*min="\d+"[^>]*name="max_age"#', $html);
        $this->assertMatchesRegularExpression('#<input[^>]*name="max_age"[^>]*max="99"|<input[^>]*max="99"[^>]*name="max_age"#', $html);
    }
}
