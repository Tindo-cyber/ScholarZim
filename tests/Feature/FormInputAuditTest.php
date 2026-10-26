<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The forms the first validation audit (ValidationAuditTest) did not reach: the admin forms, the account forms, the
 * listing report, the decision and withdrawal forms, the programme and profile detail fields, and the public filters.
 *
 * Every case goes through the real route and checks the two things that matter - the value is refused with a message on
 * the field the person can fix, and nothing was saved - and its valid neighbour is included so a tightened rule cannot
 * quietly refuse real input.
 */
class FormInputAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    private User $admin;

    private Opportunity $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake((string) config('filesystems.default', 'local'));
        Mail::fake();
        Http::fake();

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->listing = Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Audit Provider', 'title' => 'Audit Award',
            'description' => 'Used by the form audit.', 'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'award_slots' => 5, 'deadline' => Carbon::today()->addDays(30), 'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => now()->subDay(), 'created_at' => now(),
        ]);
    }

    private function as(User $user): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }

    private function pendingApplication(): Application
    {
        return Application::create([
            'user_id' => $this->student->user_id, 'opportunity_id' => $this->listing->opportunity_id,
            'application_status' => ApplicationStatus::PENDING, 'submitted_at' => now(), 'personal_statement' => str_repeat('A reason. ', 12),
        ]);
    }

    private function newPendingListing(): Opportunity
    {
        return Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Audit Provider', 'title' => 'Pending Audit Award ' . uniqid(),
            'description' => 'Waiting.', 'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'award_slots' => 2, 'deadline' => Carbon::today()->addDays(30), 'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::PENDING, 'submitted_at' => now(), 'created_at' => now(),
        ]);
    }

    // ==================================================== admin: create an account --

    private function adminCreate(array $over = [])
    {
        return $this->as($this->admin)->post('/admin/users/create', array_merge([
            'full_name' => 'Chipo Dube', 'email' => 'chipo.audit@example.com', 'phone' => '0771234567', 'role_name' => 'ROLE_APPLICANT',
            'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd',
        ], $over));
    }

    public function test_the_admin_can_create_an_account_from_valid_input(): void
    {
        $this->adminCreate()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'chipo.audit@example.com']);
    }

    public static function badAdminAccounts(): array
    {
        return [
            'name with digits' => [['full_name' => 'Chipo 123'], 'full_name'],
            'blank name' => [['full_name' => ''], 'full_name'],
            'name too long' => [['full_name' => str_repeat('a', 256)], 'full_name'],
            'bad email' => [['email' => 'not-an-email'], 'email'],
            'email too long' => [['email' => str_repeat('a', 250) . '@example.com'], 'email'],
            'duplicate email' => [['email' => 'student@scholarzim.co.zw'], 'email'],
            'phone with letters' => [['phone' => '07712ABC567'], 'phone'],
            'unknown role' => [['role_name' => 'SUPERUSER'], 'role_name'],
            'blank role' => [['role_name' => ''], 'role_name'],
            'short password' => [['password' => 'Ab1', 'password_confirmation' => 'Ab1'], 'password'],
            'password without a digit' => [['password' => 'Passwordonly', 'password_confirmation' => 'Passwordonly'], 'password'],
            'password mismatch' => [['password_confirmation' => 'Different1Pass'], 'password'],
            'password over 72' => [['password' => 'Aa1' . str_repeat('x', 75), 'password_confirmation' => 'Aa1' . str_repeat('x', 75)], 'password'],
        ];
    }

    #[DataProvider('badAdminAccounts')]
    public function test_the_admin_account_form_refuses_bad_input_and_saves_nothing(array $over, string $field): void
    {
        $before = User::count();

        $this->adminCreate($over)->assertSessionHasErrors($field);

        $this->assertSame($before, User::count());
    }

    public function test_only_an_administrator_can_create_accounts(): void
    {
        foreach ([$this->student, $this->provider] as $user) {
            $this->as($user)->post('/admin/users/create', ['full_name' => 'X Y', 'email' => 'x@example.com', 'role_name' => 'ROLE_ADMIN', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd'])
                ->assertStatus(403);
        }

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    // ============================================== admin: reasons and decisions --

    public static function reasonLengths(): array
    {
        return ['empty' => ['', false], 'one character' => ['x', true], 'at the limit' => [str_repeat('r', 500), true], 'over the limit' => [str_repeat('r', 501), false], 'array' => [['x'], false]];
    }

    #[DataProvider('reasonLengths')]
    public function test_declining_a_listing_needs_a_reason_of_a_sensible_length(mixed $reason, bool $accepted): void
    {
        $listing = $this->newPendingListing();

        $response = $this->as($this->admin)->post("/admin/opportunities/{$listing->opportunity_id}/reject", ['reason' => $reason]);

        $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('reason');
        $this->assertSame($accepted ? OpportunityModerationStatus::REJECTED : OpportunityModerationStatus::PENDING, $listing->fresh()->moderation_status);
    }

    #[DataProvider('reasonLengths')]
    public function test_rejecting_a_provider_needs_a_reason_of_a_sensible_length(mixed $reason, bool $accepted): void
    {
        $pending = User::where('email', 'trust@scholarzim.co.zw')->firstOrFail();

        $response = $this->as($this->admin)->post("/admin/users/providers/{$pending->user_id}/reject", ['reason' => $reason]);

        $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('reason');
    }

    public static function badBulkReviews(): array
    {
        return [
            'nothing selected' => [['opportunities' => [], 'decision' => 'approve'], 'opportunities'],
            'selection not a list' => [['opportunities' => 'abc', 'decision' => 'approve'], 'opportunities'],
            'a non-numeric id' => [['opportunities' => ['abc'], 'decision' => 'approve'], 'opportunities.0'],
            'a decimal id' => [['opportunities' => ['1.5'], 'decision' => 'approve'], 'opportunities.0'],
            'a negative id' => [['opportunities' => ['-4'], 'decision' => 'approve'], 'opportunities.0'],
            'a zero id' => [['opportunities' => ['0'], 'decision' => 'approve'], 'opportunities.0'],
            'an unknown decision' => [['opportunities' => ['1'], 'decision' => 'delete'], 'decision'],
            'declining without a reason' => [['opportunities' => ['1'], 'decision' => 'reject'], 'reason'],
            'a reason that is too long' => [['opportunities' => ['1'], 'decision' => 'reject', 'reason' => str_repeat('r', 501)], 'reason'],
        ];
    }

    #[DataProvider('badBulkReviews')]
    public function test_the_bulk_review_refuses_bad_input(array $payload, string $field): void
    {
        $listing = $this->newPendingListing();

        $this->as($this->admin)->post('/admin/opportunities/bulk-review', $payload)->assertSessionHasErrors($field);

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->fresh()->moderation_status);
    }

    public function test_the_bulk_review_accepts_a_valid_selection_once_each(): void
    {
        $listing = $this->newPendingListing();

        $this->as($this->admin)->post('/admin/opportunities/bulk-review', ['opportunities' => [$listing->opportunity_id, $listing->opportunity_id], 'decision' => 'approve'])
            ->assertSessionHasNoErrors();

        $this->assertSame(OpportunityModerationStatus::APPROVED, $listing->fresh()->moderation_status);
    }

    public function test_the_provider_review_decisions_must_be_one_of_the_listed_options(): void
    {
        $pending = User::where('email', 'trust@scholarzim.co.zw')->firstOrFail();

        $this->as($this->admin)->post("/admin/users/providers/{$pending->user_id}/trust", ['decision' => 'maybe'])->assertSessionHasErrors('decision');
        $this->as($this->admin)->post("/admin/users/providers/{$pending->user_id}/website", ['decision' => 'maybe'])->assertSessionHasErrors('decision');
        $this->as($this->admin)->post("/admin/users/providers/{$pending->user_id}/request-information", ['note' => ''])->assertSessionHasErrors('note', null, 'requestInfo');
        $this->as($this->admin)->post("/admin/users/providers/{$pending->user_id}/request-information", ['note' => str_repeat('n', 1001)])->assertSessionHasErrors('note', null, 'requestInfo');
    }

    public function test_admin_forms_are_closed_to_other_roles(): void
    {
        $listing = $this->newPendingListing();

        foreach ([$this->student, $this->provider] as $user) {
            $this->as($user)->post("/admin/opportunities/{$listing->opportunity_id}/approve")->assertStatus(403);
            $this->as($user)->post('/admin/opportunities/bulk-review', ['opportunities' => [$listing->opportunity_id], 'decision' => 'approve'])->assertStatus(403);
            $this->as($user)->post("/admin/users/{$this->admin->user_id}/suspend", ['reason' => 'no'])->assertStatus(403);
        }

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->fresh()->moderation_status);
        $this->assertSame('ACTIVE', $this->admin->fresh()->account_status);
    }

    // ============================================================ catalogue forms --

    public function test_the_catalogue_forms_hold_their_fields_to_their_limits(): void
    {
        $field = \App\Models\Field::query()->whereNull('parent_id')->firstOrFail();

        $this->as($this->admin)->post('/admin/catalogue/fields', ['code' => 'ab', 'name' => 'Letters', 'parent_id' => ''])->assertSessionHasErrors('code');
        $this->as($this->admin)->post('/admin/catalogue/fields', ['code' => '12345', 'name' => 'Too long a code'])->assertSessionHasErrors('code');
        $this->as($this->admin)->post('/admin/catalogue/fields', ['code' => '9801', 'name' => str_repeat('n', 151)])->assertSessionHasErrors('name');
        $this->as($this->admin)->post('/admin/catalogue/fields', ['code' => '9801', 'name' => ''])->assertSessionHasErrors('name');
        $this->as($this->admin)->post('/admin/catalogue/fields', ['code' => '9801', 'name' => '<b></b>'])->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('fields', ['code' => '9801']);

        $this->as($this->admin)->post('/admin/catalogue/institutions', ['code' => 'bad code!', 'name' => 'X', 'type' => 'university'])->assertSessionHasErrors('code');
        $this->as($this->admin)->post('/admin/catalogue/institutions', ['code' => 'OK1', 'name' => '', 'type' => 'university'])->assertSessionHasErrors('name');
        $this->as($this->admin)->post('/admin/catalogue/institutions', ['code' => 'OK1', 'name' => '<i></i>', 'type' => 'university'])->assertSessionHasErrors('name');
        $this->as($this->admin)->post('/admin/catalogue/institutions', ['code' => 'OK1', 'name' => 'Real College', 'type' => 'Spaceport'])->assertSessionHasErrors('type');
        $this->as($this->admin)->post('/admin/catalogue/institutions', ['code' => 'OK1', 'name' => 'Real College', 'type' => 'university', 'province' => 'Atlantis'])->assertSessionHasErrors('province');
        $this->assertDatabaseMissing('institutions', ['code' => 'OK1']);

        $this->as($this->admin)->post('/admin/catalogue/programmes', ['name' => '<b></b>', 'education_level' => 'UNDERGRADUATE', 'field_id' => $field->id])->assertSessionHasErrors('name');
        $this->as($this->admin)->post('/admin/catalogue/programmes', ['name' => 'Real Programme', 'education_level' => 'KINDERGARTEN', 'field_id' => $field->id])->assertSessionHasErrors('education_level');
        $this->as($this->admin)->post('/admin/catalogue/programmes', ['name' => 'Real Programme', 'education_level' => 'UNDERGRADUATE', 'field_id' => 999999])->assertSessionHasErrors('field_id');
        $this->assertDatabaseMissing('programmes', ['name' => 'Real Programme']);
    }

    // ======================================================= applicant: listing report --

    public static function badReports(): array
    {
        return [
            'no reason' => [[], 'reason'],
            'an invented reason' => [['reason' => 'because'], 'reason'],
            'details too long' => [['reason' => 'SCAM', 'details' => str_repeat('d', 1001)], 'details'],
            'details as an array' => [['reason' => 'SCAM', 'details' => ['x']], 'details'],
        ];
    }

    #[DataProvider('badReports')]
    public function test_a_listing_report_refuses_bad_input(array $payload, string $field): void
    {
        $reasons = \App\Support\ReportReason::values();
        $payload = array_map(fn ($v) => $v === 'SCAM' ? $reasons[0] : $v, $payload);

        $this->as($this->student)->post("/scholarships/{$this->listing->opportunity_id}/report", $payload)->assertSessionHasErrors($field, null, 'report-' . $this->listing->opportunity_id);
        $this->assertSame(0, \App\Models\OpportunityReport::count());
    }

    public function test_a_valid_listing_report_is_saved_and_an_unknown_listing_is_a_404(): void
    {
        $reason = \App\Support\ReportReason::values()[0];

        $this->as($this->student)->post("/scholarships/{$this->listing->opportunity_id}/report", ['reason' => $reason, 'details' => str_repeat('d', 1000)])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, \App\Models\OpportunityReport::count());

        $this->as($this->student)->post('/scholarships/999999/report', ['reason' => $reason])->assertNotFound();
    }

    // ================================================ provider: decision and withdrawal --

    #[DataProvider('reasonLengths')]
    public function test_a_provider_decision_needs_a_reason_of_a_sensible_length(mixed $reason, bool $accepted): void
    {
        $application = $this->pendingApplication();

        $response = $this->as($this->provider)->post("/provider/applications/{$application->application_id}/review", ['status' => 'REJECTED', 'reason' => $reason]);

        $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('reason');
        $this->assertSame($accepted ? 'REJECTED' : 'PENDING', ApplicationStatus::canonical($application->fresh()->application_status));
    }

    public function test_an_acceptance_message_has_a_limit_and_other_providers_cannot_decide(): void
    {
        $application = $this->pendingApplication();
        $max = \App\Services\ApplicationService::MAX_ACCEPTANCE_MESSAGE;

        $this->as($this->provider)->post("/provider/applications/{$application->application_id}/review", ['status' => 'ACCEPTED', 'reason' => 'Welcome', 'message' => str_repeat('m', $max + 1)])
            ->assertSessionHasErrors('message');

        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $this->as($other)->post("/provider/applications/{$application->application_id}/review", ['status' => 'REJECTED', 'reason' => 'Not yours'])->assertStatus(403);
        $this->assertSame('PENDING', ApplicationStatus::canonical($application->fresh()->application_status));
    }

    #[DataProvider('reasonLengths')]
    public function test_a_withdrawal_reason_has_a_limit(mixed $reason, bool $accepted): void
    {
        if ($reason === '') {
            $this->markTestSkipped('A withdrawal reason is optional, so an empty one is valid.');
        }

        $application = $this->pendingApplication();

        $response = $this->as($this->student)->post("/applications/{$application->application_id}/withdraw", ['reason' => $reason]);

        $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('reason');
    }

    public function test_an_applicant_cannot_withdraw_someone_elses_application(): void
    {
        $application = $this->pendingApplication();
        $other = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();

        $this->as($other)->post("/applications/{$application->application_id}/withdraw", ['reason' => 'x'])->assertSessionHas('errorMessage');
        $this->assertSame('PENDING', ApplicationStatus::canonical($application->fresh()->application_status));
    }

    // ============================================================== extend a deadline --

    public static function deadlines(): array
    {
        return [
            'yesterday' => [fn () => Carbon::yesterday()->toDateString(), false],
            'today' => [fn () => Carbon::today()->toDateString(), false],
            'not a date' => [fn () => 'tomorrow-ish', false],
            'wrong format' => [fn () => Carbon::today()->addDays(40)->format('d/m/Y'), false],
            'impossible date' => [fn () => '2030-02-30', false],
            'an array' => [fn () => ['2030-01-01'], false],
            'next month' => [fn () => Carbon::today()->addDays(60)->toDateString(), true],
        ];
    }

    #[DataProvider('deadlines')]
    public function test_extending_a_deadline(callable $date, bool $accepted): void
    {
        $response = $this->as($this->provider)->post("/opportunities/{$this->listing->opportunity_id}/extend-deadline", ['deadline' => $date(), 'reason' => 'More time']);

        $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('deadline', null, 'extend-deadline-' . $this->listing->opportunity_id);
    }

    // ================================================================ account forms --

    public function test_changing_the_password_needs_the_current_one_and_a_strong_new_one(): void
    {
        $change = fn (array $over) => $this->as($this->student)->post('/account/password', array_merge([
            'current_password' => 'ChangeMe123', 'password' => 'N3wStrongPass', 'password_confirmation' => 'N3wStrongPass',
        ], $over));

        $change(['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $change(['current_password' => ''])->assertSessionHasErrors('current_password');
        $change(['password' => 'short1A', 'password_confirmation' => 'short1A'])->assertSessionHasErrors('password');
        $change(['password' => 'alllowercase1', 'password_confirmation' => 'alllowercase1'])->assertSessionHasErrors('password');
        $change(['password_confirmation' => 'Mismatch123'])->assertSessionHasErrors('password');
        $change(['password' => ['x'], 'password_confirmation' => ['x']])->assertSessionHasErrors('password');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('ChangeMe123', $this->student->fresh()->password_hash), 'refused changes left the password alone');

        $change([])->assertSessionHasNoErrors();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('N3wStrongPass', $this->student->fresh()->password_hash));
    }

    public function test_deleting_an_account_needs_the_password_and_the_exact_email(): void
    {
        $delete = fn (array $over) => $this->as($this->student)->post('/account/delete', array_merge(['current_password' => 'ChangeMe123', 'confirm_email' => $this->student->email], $over));

        $delete(['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $delete(['confirm_email' => 'someone@else.com'])->assertSessionHas('errorMessage');
        $delete(['confirm_email' => ''])->assertSessionHasErrors('confirm_email');
        $delete(['confirm_email' => ['x']])->assertSessionHasErrors('confirm_email');

        $this->assertNotNull(User::find($this->student->user_id), 'a refused request deleted nothing');
    }

    public function test_notification_preferences_treat_anything_odd_as_off_and_never_fail(): void
    {
        $this->as($this->student)->post('/account/notification-preferences', ['email_notify_applications' => 'banana', 'email_notify_system' => ['x']])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $this->student->fresh()->email_notify_applications);
    }

    // ============================================================ applicant: programmes --

    public static function badProgrammeInput(): array
    {
        return [
            'a programme id that is text' => [['current_programme_id' => 'abc'], 'current_programme_id'],
            'a decimal programme id' => [['current_programme_id' => '1.5'], 'current_programme_id'],
            'too many intended programmes' => [['intended_programme_ids' => range(1, 21)], 'intended_programme_ids'],
            'an intended id that is text' => [['intended_programme_ids' => ['x']], 'intended_programme_ids.0'],
            'a suggestion that is too long' => [['programme_suggestion' => str_repeat('p', 201)], 'programme_suggestion'],
            'a suggestion level that is too long' => [['programme_suggestion_level' => str_repeat('p', 31)], 'programme_suggestion_level'],
            'too many target fields' => [['target_field_ids' => range(1, 21)], 'target_field_ids'],
        ];
    }

    #[DataProvider('badProgrammeInput')]
    public function test_the_programme_form_refuses_bad_input(array $payload, string $field): void
    {
        $this->as($this->student)->post('/applicant/profile/programmes', $payload)->assertSessionHasErrors($field);
    }

    public function test_programme_ids_that_do_not_exist_are_not_saved_and_never_a_server_error(): void
    {
        foreach ([['current_programme_id' => '999999'], ['current_programme_id' => '-5'], ['current_programme_id' => '0'],
            ['intended_programme_ids' => ['999999', '-1', '0']], ['target_field_ids' => ['999999', '-1']]] as $payload) {
            $response = $this->as($this->student)->post('/applicant/profile/programmes', $payload);

            $this->assertLessThan(500, $response->getStatusCode(), json_encode($payload));
        }

        $this->assertSame(0, \App\Models\ApplicantProgramme::where('profile_id', ApplicantProfile::where('user_id', $this->student->user_id)->value('profile_id'))
            ->whereNotIn('programme_id', \App\Models\Programme::pluck('id'))->count(), 'nothing points at a programme that does not exist');
    }

    // =========================================================== applicant: profile fields --

    private function profilePost(array $over = [])
    {
        $profile = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();

        return $this->as($this->student)->post('/applicant/profile', array_merge([
            'full_name' => $this->student->full_name, 'education_level' => $profile->education_level, 'gender' => $profile->gender ?: 'male',
        ], $over));
    }

    public static function profileFields(): array
    {
        return [
            'institution at the limit' => [['institution_name' => str_repeat('i', 255)], null],
            'institution over the limit' => [['institution_name' => str_repeat('i', 256)], 'institution_name'],
            'province not in the list' => [['province' => 'Atlantis'], 'province'],
            'gender not in the list' => [['gender' => 'robot'], 'gender'],
            'biography over the limit' => [['biography' => str_repeat('b', 5001)], 'biography'],
            'date of birth in the future' => [['date_of_birth' => '2999-01-01'], 'date_of_birth'],
            'date of birth before 1920' => [['date_of_birth' => '1919-12-31'], 'date_of_birth'],
            'date of birth impossible' => [['date_of_birth' => '2000-02-30'], 'date_of_birth'],
            'date of birth not a date' => [['date_of_birth' => 'yesterday'], 'date_of_birth'],
            'education level invented' => [['education_level' => 'WIZARD'], 'education_level'],
            'phone with letters' => [['phone' => 'abc'], 'phone'],
            'name with digits' => [['full_name' => 'Tendai 7'], 'full_name'],
            'name too long' => [['full_name' => str_repeat('a', 256)], 'full_name'],
            'result year before 1950' => [['academic_results_submitted' => '1', 'academic_subject_results' => [['qualification_id' => 1, 'subject_id' => 1, 'result' => 'A', 'year' => 1949]]], 'academic_subject_results.0.year'],
            'result year far in the future' => [['academic_results_submitted' => '1', 'academic_subject_results' => [['qualification_id' => 1, 'subject_id' => 1, 'result' => 'A', 'year' => 2999]]], 'academic_subject_results.0.year'],
            'result with no grade' => [['academic_results_submitted' => '1', 'academic_subject_results' => [['qualification_id' => 1, 'subject_id' => 1, 'result' => '', 'year' => 2020]]], 'academic_subject_results.0.result'],
            'result for a subject that does not exist' => [['academic_results_submitted' => '1', 'academic_subject_results' => [['qualification_id' => 1, 'subject_id' => 999999, 'result' => 'A']]], 'academic_subject_results.0.subject_id'],
            'more than forty results' => [['academic_results_submitted' => '1', 'academic_subject_results' => array_fill(0, 41, ['qualification_id' => 1, 'subject_id' => 1, 'result' => 'A'])], 'academic_subject_results'],
        ];
    }

    #[DataProvider('profileFields')]
    public function test_the_profile_form(array $over, ?string $errorOn): void
    {
        $response = $this->profilePost($over);

        $errorOn === null ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors($errorOn);
    }

    public function test_a_refused_profile_save_changes_nothing(): void
    {
        $before = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail()->only(['institution_name', 'province', 'biography', 'gender']);

        $this->profilePost(['institution_name' => 'Changed College', 'province' => 'Atlantis'])->assertSessionHasErrors('province');

        $this->assertSame($before, ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail()->only(['institution_name', 'province', 'biography', 'gender']));
    }

    // ======================================================== public search and filters --

    public static function oddFilters(): array
    {
        return [
            'a negative page' => ['page=-3'], 'a zero page' => ['page=0'], 'a page that is text' => ['page=abc'], 'a huge page' => ['page=99999999999999999999'],
            'level not in the list' => ['level=WIZARD'], 'level as an array' => ['level[]=A_LEVEL&level[]=PHD'], 'unknown sort' => ['sort=drop_table'],
            'sort as an array' => ['sort[]=x'], 'unknown country' => ['country=Atlantis'], 'unknown province' => ['province=Atlantis'],
            'keyword as an array' => ['keyword[]=a'], 'very long keyword' => ['keyword=' . '%41' . '=' . str_repeat('a', 5000)], 'funding as array' => ['funding[a]=b'],
            'a negative amount' => ['min_amount=-5&max_amount=abc'], 'a deadline that is not a date' => ['deadline=not-a-date'],
        ];
    }

    #[DataProvider('oddFilters')]
    public function test_the_public_filters_never_fail_on_odd_values(string $query): void
    {
        $response = $this->get('/scholarships?' . $query);

        $this->assertLessThan(500, $response->getStatusCode(), $query);
        $this->assertDoesNotMatchRegularExpression('/SQLSTATE|Stack trace/', $response->getContent());
    }
    // ===================================================== the browser's limits match the server's --

    /** The `max:N` a rule list states, or null. */
    private function maxOf(array $rules): ?int
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', $rule, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    public function test_every_browser_limit_is_the_servers_own_limit(): void
    {
        $listing = (new \App\Http\Requests\StoreOpportunityRequest())->rules();
        $profile = \App\Support\ProfileDetailRules::base();
        $email = \App\Rules\EmailAddress::rules();
        $expected = [
            'title' => $this->maxOf($listing['title']), 'description' => $this->maxOf($listing['description']),
            'provider_display_name' => $this->maxOf($listing['provider_display_name']), 'external_url' => $this->maxOf($listing['external_url']),
            'other_conditions' => $this->maxOf($listing['other_conditions']), 'host_institutions' => $this->maxOf($listing['host_institutions']),
            'institution_name' => $this->maxOf($profile['institution_name']), 'field_of_study' => $this->maxOf($profile['field_of_study']),
            'locality' => $this->maxOf($profile['locality']), 'guardian_name' => $this->maxOf($profile['guardian_name']),
            'guardian_relationship' => $this->maxOf($profile['guardian_relationship']), 'biography' => $this->maxOf($profile['biography']),
            'target_locality' => $this->maxOf($listing['target_locality']), 'email' => $this->maxOf($email),
            'message' => \App\Services\ApplicationService::MAX_ACCEPTANCE_MESSAGE, 'personal_statement' => 5000, 'reason' => 500, 'password' => 72,
        ];

        foreach ($expected as $field => $max) {
            $this->assertSame($max, \App\Support\FieldLimits::for($field), "the browser limit for $field is not the server's");
        }
    }

    public function test_the_forms_carry_the_limits(): void
    {
        $this->flushSession();
        $register = $this->get('/register')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#name="full_name"[^>]*maxlength="255"|maxlength="255"[^>]*name="full_name"#', $register);
        $this->assertMatchesRegularExpression('#name="email"[^>]*maxlength="254"|maxlength="254"[^>]*name="email"#', $register);
        $this->assertMatchesRegularExpression('#name="password"[^>]*maxlength="72"|maxlength="72"[^>]*name="password"#', $register);

        $profile = $this->as($this->student)->get('/applicant/profile')->assertOk()->getContent();
        foreach (['institution_name' => 255, 'locality' => 100, 'guardian_name' => 255, 'guardian_relationship' => 100, 'biography' => 5000] as $field => $max) {
            $this->assertMatchesRegularExpression('#<(input|textarea)[^>]*name="' . $field . '"[^>]*maxlength="' . $max . '"|<(input|textarea)[^>]*maxlength="' . $max . '"[^>]*name="' . $field . '"#', $profile, $field);
        }

        $create = $this->as($this->provider)->get('/opportunities/create')->assertOk()->getContent();
        foreach (['title' => 255, 'description' => 10000] as $field => $max) {
            $this->assertMatchesRegularExpression('#<(input|textarea)[^>]*name="' . $field . '"[^>]*maxlength="' . $max . '"|<(input|textarea)[^>]*maxlength="' . $max . '"[^>]*name="' . $field . '"#', $create, $field);
        }
    }
    // ====================================================== provider registration and sign-in --

    private function providerRegistration(array $over = [])
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->post('/register/provider', array_merge([
            'full_name' => 'Chikafu Education Trust', 'contact_name' => 'Rudo Moyo', 'contact_role' => 'Director', 'email' => 'rudo.audit@example.org',
            'phone' => '+263 242 700 000', 'organisation_type' => \App\Support\ProviderOrgType::ALL[0], 'registration_country' => 'ZW',
            'registration_number' => 'MT 123/2020', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd', 'authorised' => '1',
            'certificate' => \Illuminate\Http\UploadedFile::fake()->createWithContent('cert.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
        ], $over));
    }

    public function test_a_provider_can_register_with_valid_input(): void
    {
        $this->providerRegistration()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'rudo.audit@example.org']);
    }

    public static function badProviderRegistrations(): array
    {
        return [
            'organisation name with no letters' => [['full_name' => '12345 !!!'], 'full_name'],
            'organisation name too long' => [['full_name' => str_repeat('a', 256)], 'full_name'],
            'contact name with digits' => [['contact_name' => 'R4do 99'], 'contact_name'],
            'contact name of only digits' => [['contact_name' => '123456'], 'contact_name'],
            'contact name too long' => [['contact_name' => str_repeat('a', 256)], 'contact_name'],
            'position with no letters' => [['contact_role' => '12345'], 'contact_role'],
            'position too long' => [['contact_role' => str_repeat('a', 151)], 'contact_role'],
            'unknown organisation type' => [['organisation_type' => 'Spaceship'], 'organisation_type'],
            'unknown registration country' => [['registration_country' => 'XX'], 'registration_country'],
            'registration number with a script' => [['registration_number' => '<script>alert(1)</script>'], 'registration_number'],
            'phone with letters' => [['phone' => 'call me'], 'phone'],
            'website that is not a web address' => [['website' => 'javascript:alert(1)'], 'website'],
            'authorisation not ticked' => [['authorised' => ''], 'authorised'],
            'a certificate that is not a document' => [['certificate' => 'plain text'], 'certificate'],
            'no certificate' => [['certificate' => null], 'certificate'],
        ];
    }

    #[DataProvider('badProviderRegistrations')]
    public function test_provider_registration_refuses_bad_input_and_creates_nothing(array $over, string $field): void
    {
        $before = User::count();

        $this->providerRegistration($over)->assertSessionHasErrors($field);

        $this->assertSame($before, User::count());
    }

    public function test_sign_in_and_reset_forms_hold_their_fields_to_a_length(): void
    {
        // The throttle is its own test; here every refusal must come from the field rules.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $long = str_repeat('a', 250) . '@example.com';

        $this->flushSession();
        $this->post('/login', ['email' => $long, 'password' => 'x'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'a@example.com', 'password' => str_repeat('p', 256)])->assertSessionHasErrors('password');
        $this->post('/login', ['email' => ['a@example.com'], 'password' => 'x'])->assertSessionHasErrors('email');
        $this->post('/forgot-password', ['email' => $long])->assertSessionHasErrors('email');
        $this->post('/forgot-password', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->post('/forgot-password', ['email' => ''])->assertSessionHasErrors('email');
        $this->post('/reset-password/not-a-real-token', ['password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
    }

    public function test_a_reset_with_a_made_up_token_changes_no_password(): void
    {
        $this->flushSession();
        $before = $this->student->fresh()->password_hash;

        $this->post('/reset-password/' . str_repeat('z', 60), ['password' => 'N3wStrongPass', 'password_confirmation' => 'N3wStrongPass'])
            ->assertRedirect(route('password.request'));

        $this->assertSame($before, $this->student->fresh()->password_hash);
    }
    // ================================================================ wording on the browse page --

    public function test_the_browse_page_filter_button_is_not_called_apply(): void
    {
        foreach ([$this->provider, $this->student] as $user) {
            $html = $this->as($user)->get('/opportunities')->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<button[^>]*type="submit"[^>]*>\s*Filter\b#', $html);
            $this->assertDoesNotMatchRegularExpression('#<button[^>]*>\s*Apply\b#', $html);
        }
    }

    public function test_a_provider_is_never_offered_an_apply_link_on_the_browse_page(): void
    {
        $html = $this->as($this->provider)->get('/opportunities')->assertOk()->getContent();

        $this->assertStringNotContainsString('>Apply</a>', $html);
        $this->assertStringNotContainsString('Sign in to apply', $html);
        $this->assertStringNotContainsString('/apply/', $html);
    }
}
