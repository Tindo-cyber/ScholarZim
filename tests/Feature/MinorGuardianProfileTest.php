<?php

namespace Tests\Feature;

use App\Exceptions\GuardianRequiredException;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicationService;
use App\Support\Deadlines;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GivesRequiredDocuments;
use Tests\TestCase;

/**
 * An applicant under 18 cannot save the profile without a parent or guardian's details.
 *
 * "Under 18" is the rule the rest of ScholarZim uses (ApplicantProfile::isMinor): by date of birth, and with no date of birth a
 * pupil at a school level (Primary, O-Level, A-Level) counts as one - so leaving the date of birth blank is not a way round it.
 * The details are the four the form asks for: the guardian's name, phone number and relationship, and the tick confirming a
 * parent or guardian is aware of and involved in the applicant's applications.
 *
 * The profile used to save without them (every guardian rule was "nullable") and report "Profile saved"; they were only demanded
 * later, when an application was submitted. Every case here goes through the real route and then reads the database.
 */
class MinorGuardianProfileTest extends TestCase
{
    use GivesRequiredDocuments;
    use RefreshDatabase;

    private const GUARDIAN_FIELDS = ['guardian_name', 'guardian_phone', 'guardian_relationship', 'guardian_confirmed'];

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // An A-Level applicant (a school level) with no guardian details on file.
        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->profile()->update(['guardian_name' => null, 'guardian_phone' => null, 'guardian_relationship' => null, 'guardian_confirmed_at' => null]);
    }

    // ------------------------------------------------------------------ helpers --

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function dob(int $years, int $days = 0): string
    {
        return Deadlines::today()->subYears($years)->subDays($days)->toDateString();
    }

    private function save(array $over = [])
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->student->fresh())->from('/applicant/profile')->post('/applicant/profile', array_merge([
            'full_name' => $this->student->full_name, 'gender' => 'male', 'education_level' => EducationLevel::A_LEVEL,
            'date_of_birth' => $this->dob(15), 'institution_name' => 'Entered At This Save',
        ], $over));
    }

    private function guardian(array $over = []): array
    {
        return array_merge([
            'guardian_name' => 'Grace Moyo', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother', 'guardian_confirmed' => '1',
        ], $over);
    }

    private function assertNothingSaved(string $institutionBefore): void
    {
        $this->assertSame($institutionBefore, $this->profile()->institution_name, 'a refused save changes nothing');
    }

    // ============================================================== 1. cannot save --

    public function test_an_underage_applicant_cannot_save_without_guardian_details(): void
    {
        $before = $this->profile()->institution_name;

        $this->save()
            ->assertSessionHasErrors(self::GUARDIAN_FIELDS)
            ->assertSessionMissing('successMessage');

        $this->assertNothingSaved($before);
        $this->assertNull($this->profile()->guardian_name);
    }

    public static function incompleteGuardians(): array
    {
        return [
            'no name' => [['guardian_name' => ''], 'guardian_name'],
            'a blank name' => [['guardian_name' => '   '], 'guardian_name'],
            'no phone' => [['guardian_phone' => ''], 'guardian_phone'],
            'no relationship' => [['guardian_relationship' => ''], 'guardian_relationship'],
            'the tick left out' => [['guardian_confirmed' => '0'], 'guardian_confirmed'],
            'a phone with letters' => [['guardian_phone' => 'call-me'], 'guardian_phone'],
            'a name with digits' => [['guardian_name' => 'Gr4ce 99'], 'guardian_name'],
        ];
    }

    #[DataProvider('incompleteGuardians')]
    public function test_each_guardian_detail_is_required_and_named_when_it_is_missing(array $over, string $field): void
    {
        $before = $this->profile()->institution_name;

        $this->save($this->guardian($over))->assertSessionHasErrors($field)->assertSessionMissing('successMessage');

        $this->assertNothingSaved($before);
    }

    // =============================================================== 2. can save --

    public function test_an_underage_applicant_saves_once_every_detail_is_given(): void
    {
        $this->save($this->guardian())->assertSessionHasNoErrors()->assertSessionHas('successMessage');

        $saved = $this->profile();
        $this->assertSame('Entered At This Save', $saved->institution_name);
        $this->assertSame('Grace Moyo', $saved->guardian_name);
        $this->assertSame('0771234567', $saved->guardian_phone);
        $this->assertSame('Mother', $saved->guardian_relationship);
        $this->assertNotNull($saved->guardian_confirmed_at);
        $this->assertSame([], $saved->missingGuardianDetails());
    }

    public function test_a_minor_whose_details_are_already_saved_can_change_something_else_without_resending_them(): void
    {
        $this->save($this->guardian())->assertSessionHasNoErrors();

        // A partial request that does not mention the guardian at all is judged against what is on file.
        $this->flushSession();
        $this->actingAs($this->student->fresh())->post('/applicant/profile', [
            'full_name' => $this->student->full_name, 'gender' => 'male', 'education_level' => EducationLevel::A_LEVEL,
            'date_of_birth' => $this->dob(15), 'biography' => 'Changed later.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Changed later.', $this->profile()->biography);
        $this->assertSame('Grace Moyo', $this->profile()->guardian_name);
    }

    // ================================================================= 3. adults --

    public function test_an_adult_saves_without_any_guardian_details(): void
    {
        $this->save(['date_of_birth' => $this->dob(25)])->assertSessionHasNoErrors()->assertSessionHas('successMessage');

        $saved = $this->profile();
        $this->assertSame('Entered At This Save', $saved->institution_name);
        $this->assertFalse($saved->isMinor());
        $this->assertNull($saved->guardian_name);
    }

    public function test_the_eighteenth_birthday_is_the_line(): void
    {
        // Exactly 18 today: an adult. A day short of 18: still a minor.
        $this->save(['date_of_birth' => $this->dob(18)])->assertSessionHasNoErrors();
        $this->assertFalse($this->profile()->isMinor());

        $this->save(['date_of_birth' => $this->dob(18, -1), 'institution_name' => 'Day Short'])->assertSessionHasErrors('guardian_name');
        $this->assertNotSame('Day Short', $this->profile()->institution_name);
    }

    public function test_an_adult_at_a_university_level_is_not_asked_even_with_no_date_of_birth(): void
    {
        $this->save(['education_level' => EducationLevel::UNDERGRADUATE, 'date_of_birth' => ''])->assertSessionHasNoErrors();
    }

    // ============================================== 4. the date of birth cannot be a way round --

    public function test_leaving_the_date_of_birth_blank_at_a_school_level_does_not_avoid_the_question(): void
    {
        $before = $this->profile()->institution_name;

        $this->save(['date_of_birth' => ''])->assertSessionHasErrors('guardian_name')->assertSessionMissing('successMessage');

        $this->assertNothingSaved($before);
        $this->assertStringContainsString('If you are 18 or over, add your date of birth', (string) session('errors')->first('guardian_name'));
    }

    public static function unusableDates(): array
    {
        return [
            'in the future' => [fn () => Deadlines::today()->addYear()->toDateString()],
            'a day that does not exist' => [fn () => '2010-02-30'],
            'not a date' => [fn () => 'yesterday'],
            'an array' => [fn () => ['2010-01-01']],
        ];
    }

    #[DataProvider('unusableDates')]
    public function test_an_unusable_date_of_birth_is_refused_and_cannot_be_used_to_slip_past(callable $date): void
    {
        $before = $this->profile()->institution_name;

        $this->save(['date_of_birth' => $date()])->assertSessionHasErrors('date_of_birth')->assertSessionMissing('successMessage');

        $this->assertNothingSaved($before);
    }

    public function test_moving_up_a_level_does_not_make_a_minor_an_adult(): void
    {
        $before = $this->profile()->institution_name;

        // Fifteen years old, claiming a university level: the date of birth decides, not the level.
        $this->save(['education_level' => EducationLevel::UNDERGRADUATE])->assertSessionHasErrors('guardian_name');

        $this->assertNothingSaved($before);
    }

    public function test_a_minor_cannot_clear_what_is_already_saved(): void
    {
        $this->save($this->guardian())->assertSessionHasNoErrors();

        $this->save($this->guardian(['guardian_name' => '', 'guardian_phone' => '', 'institution_name' => 'Cleared It']))
            ->assertSessionHasErrors(['guardian_name', 'guardian_phone']);

        $this->assertSame('Grace Moyo', $this->profile()->guardian_name);
        $this->assertNotSame('Cleared It', $this->profile()->institution_name);
    }

    // ===================================================== 5. applying cannot get round it either --

    private function listing(): Opportunity
    {
        return Opportunity::create([
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->firstOrFail()->user_id,
            'provider_name' => 'Open Provider', 'title' => 'Open Award', 'description' => 'No stated requirements.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'award_slots' => 3,
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'created_at' => now(),
        ]);
    }

    public function test_an_incomplete_minor_profile_still_cannot_submit_an_application(): void
    {
        // However the incomplete profile came to exist (older data, an administrator, a direct write), applying is refused.
        $this->profile()->forceFill(['date_of_birth' => $this->dob(15)])->save();
        $listing = $this->listing();
        $this->giveRequiredDocuments($this->student, $listing); // the guardian is the only thing missing

        $this->expectException(GuardianRequiredException::class);
        app(ApplicationService::class)->submit($listing->opportunity_id, $this->student->fresh(), ['personal_statement' => str_repeat('A good reason. ', 10)]);
    }

    public function test_one_click_apply_and_the_form_refuse_an_incomplete_minor_and_create_nothing(): void
    {
        $this->profile()->forceFill(['date_of_birth' => $this->dob(15)])->save();
        $listing = $this->listing();
        $this->giveRequiredDocuments($this->student, $listing);

        $this->flushSession();
        $this->actingAs($this->student->fresh())->post(route('applications.quick', $listing->opportunity_id))->assertSessionHas('errorMessage');
        $this->actingAs($this->student->fresh())->post(route('applications.submit', $listing->opportunity_id), [
            'personal_statement' => str_repeat('I want to study and give back. ', 5), 'confirm' => '1',
        ])->assertSessionHas('errorMessage');

        $this->assertSame(0, Application::where('user_id', $this->student->user_id)->where('opportunity_id', $listing->opportunity_id)->count());
    }

    public function test_a_minor_completing_the_profile_can_then_apply(): void
    {
        $this->save($this->guardian())->assertSessionHasNoErrors();
        $listing = $this->listing();
        $this->giveRequiredDocuments($this->student, $listing);

        $this->flushSession();
        $this->actingAs($this->student->fresh())->post(route('applications.quick', $listing->opportunity_id))->assertSessionMissing('errorMessage');

        $this->assertSame(1, Application::where('user_id', $this->student->user_id)->where('opportunity_id', $listing->opportunity_id)->count());
    }

    public function test_the_apply_page_cannot_be_used_to_save_a_minor_without_a_guardian_either(): void
    {
        $this->profile()->forceFill(['date_of_birth' => $this->dob(15), 'gender' => null])->save();
        $listing = $this->listing();

        // The apply page's "add what is missing" form asks for the guardian too; posting only the gender does not get past it.
        $this->flushSession();
        $this->actingAs($this->student->fresh())->post(route('applications.details', $listing->opportunity_id), ['profile' => ['gender' => 'male']])
            ->assertSessionHasErrors(['profile.guardian_name']);

        $this->assertNull($this->profile()->gender, 'nothing was saved by the refused request');
    }

    // ============================================== 6. the errors are shown, nothing false is claimed --

    public function test_the_page_shows_what_is_missing_keeps_what_was_typed_and_claims_no_success(): void
    {
        $this->save(['institution_name' => 'Kept On The Page'])->assertSessionHasErrors(self::GUARDIAN_FIELDS);

        $page = $this->actingAs($this->student->fresh())->get('/applicant/profile')->assertOk()->getContent();

        $this->assertStringContainsString("Enter your parent or guardian&#039;s full name", $page);
        $this->assertStringContainsString("phone number.", $page);
        $this->assertStringContainsString('Say how your parent or guardian is related to you', $page);
        $this->assertStringContainsString('Tick the box to confirm a parent or guardian is aware', $page);
        $this->assertStringNotContainsString('Profile saved', $page);
        $this->assertStringContainsString('Kept On The Page', $page, 'what was typed is still in the form');
        $this->assertDoesNotMatchRegularExpression('#id="sz-guardian-card"[^>]*\bhidden\b#', $page, 'the card holding the errors is visible, whatever the saved date of birth says');
    }

    public function test_the_form_asks_for_the_details_in_the_browser_too(): void
    {
        $this->profile()->forceFill(['date_of_birth' => $this->dob(15)])->save();

        $page = $this->actingAs($this->student->fresh())->get('/applicant/profile')->assertOk()->getContent();

        $this->assertStringContainsString('These details are', $page);
        $this->assertStringContainsString('required to save your profile', preg_replace('/\s+/', ' ', $page));
        $this->assertStringNotContainsString('build your profile without these details', preg_replace('/\s+/', ' ', $page));
    }
}
