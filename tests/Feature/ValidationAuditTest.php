<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ProviderOrgType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A validation audit: what each field accepts and refuses, whatever the form in the browser says.
 *
 * Every test here goes through the real route - no frontend, no helper in isolation - and checks the two things that matter:
 * the invalid value is refused with a message, and nothing was saved or started because of it. The valid counterpart of each
 * case is included so a tightened rule cannot quietly refuse real people.
 */
class ValidationAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    private User $admin;

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
    }

    // ------------------------------------------------------------------ helpers --

    private function pdf(string $name = 'doc.pdf', ?string $body = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body ?? "%PDF-1.4\n% $name\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    /**
     * A genuine file on disk. Laravel's fake uploads are judged by their NAME, so a script called cv.pdf would look like a PDF;
     * a real one is judged by what is inside it, which is what happens in production.
     */
    private function realFile(string $name, string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sz');
        file_put_contents($path, $body);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function register(array $over = [])
    {
        $this->flushSession();

        return $this->post('/register', array_merge([
            'full_name' => 'Tendai Moyo', 'email' => 'tendai.new@example.com', 'phone' => '0771234567',
            'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd',
        ], $over));
    }

    private function registerProvider(array $over = [])
    {
        $this->flushSession();

        return $this->post('/register/provider', array_merge([
            'full_name' => 'Chikafu Education Trust', 'contact_name' => 'Rudo Chikafu', 'contact_role' => 'Director',
            'email' => 'trust.new@example.com', 'phone' => '+263242771234', 'organisation_type' => ProviderOrgType::ALL[0],
            'registration_country' => 'ZW', 'registration_number' => '123/2020', 'authorised' => '1',
            'certificate' => $this->pdf('certificate.pdf'),
            'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd',
        ], $over));
    }

    private function usersBefore(): int
    {
        return User::count();
    }

    private function listingPayload(array $over = []): array
    {
        return array_merge([
            'title' => 'Audit Award', 'description' => 'A listing used by the audit.',
            'education_level' => EducationLevel::MASTERS, 'country' => 'Zimbabwe',
        ], $over);
    }

    private function createListing(array $over = [])
    {
        $this->flushSession();

        return $this->actingAs($this->provider)->post('/opportunities/create', $this->listingPayload($over));
    }

    private function liveListing(array $over = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Audit Provider', 'title' => 'Live Audit Award ' . uniqid(),
            'description' => 'No stated requirements.', 'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30), 'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => now()->subDay(),
        ], $over));
    }

    // ================================================================ 1. names --

    public static function validNames(): array
    {
        return [
            'plain' => ['Tendai Moyo'], 'hyphen' => ['Mary-Ann Ncube'], 'apostrophe' => ["Nomsa O'Neil"],
            'curly apostrophe (what a phone types)' => ["Nomsa D\u{2019}Souza"], 'accents' => ['José Zoë Müller'],
            'decomposed accent (e + combining mark)' => ["Jose\u{0301} Moyo"], 'initials' => ['J. K. Moyo'],
            'non-latin' => ['Иван Петров'], 'two letters' => ['Li'],
        ];
    }

    public static function invalidNames(): array
    {
        return [
            'digits' => ['Tendai 123'], 'only digits' => ['12345'], 'symbols' => ['Tendai @ Moyo'], 'script' => ['<script>alert(1)</script>'],
            'punctuation only' => ['---'], 'a lone apostrophe' => ["'"], 'a lone hyphen' => ['-'], 'a lone dot' => ['.'],
            'newline inside' => ["Tendai\nMoyo"], 'tab inside' => ["Tendai\tMoyo"], 'sql' => ["Robert'); DROP TABLE users;--"],
            'emoji' => ["Tendai \u{1F600}"], 'too long' => [str_repeat('a', 256)], 'whitespace only' => ['     '],
        ];
    }

    #[DataProvider('validNames')]
    public function test_a_legitimate_name_is_accepted_and_saved_as_written(string $name): void
    {
        $this->register(['full_name' => $name])->assertSessionHasNoErrors();

        $this->assertSame(trim($name), User::where('email', 'tendai.new@example.com')->firstOrFail()->full_name);
    }

    #[DataProvider('invalidNames')]
    public function test_an_invalid_name_is_refused_and_nothing_is_saved(string $name): void
    {
        $before = $this->usersBefore();

        $this->register(['full_name' => $name])->assertSessionHasErrors('full_name');

        $this->assertSame($before, $this->usersBefore());
    }

    public function test_the_name_is_trimmed_before_it_is_judged_and_stored(): void
    {
        $this->register(['full_name' => "   Tendai Moyo \n"])->assertSessionHasNoErrors();

        $this->assertSame('Tendai Moyo', User::where('email', 'tendai.new@example.com')->firstOrFail()->full_name);
    }

    public function test_the_profile_form_holds_names_to_the_same_rule(): void
    {
        foreach (self::validNames() as $case => [$name]) {
            $this->flushSession();
            $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), [
                'full_name' => $name, 'gender' => 'male', 'province' => 'Harare',
            ])->assertSessionDoesntHaveErrors('full_name');
        }

        foreach (['Tendai 123', '---', "'", '<b>x</b>'] as $name) {
            $this->flushSession();
            $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), ['full_name' => $name, 'gender' => 'male'])->assertSessionHasErrors('full_name');
        }
    }

    public function test_a_guardian_name_follows_the_same_rule(): void
    {
        foreach ([["Mary D\u{2019}Souza", false], ['Mary 7', true], ['---', true]] as [$name, $refused]) {
            $this->flushSession();
            $r = $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), [
                'full_name' => $this->student->full_name, 'gender' => 'male', 'guardian_name' => $name,
            ]);
            $refused ? $r->assertSessionHasErrors('guardian_name') : $r->assertSessionDoesntHaveErrors('guardian_name');
        }
    }

    public function test_an_organisation_name_may_hold_digits_and_ampersands(): void
    {
        $this->registerProvider(['full_name' => 'A & B Education 2000 (Pvt) Ltd'])->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('email', 'trust.new@example.com')->first());
    }

    // =============================================================== 2. emails --

    public static function badEmails(): array
    {
        return [
            'no at' => ['tendai.example.com'], 'two ats' => ['a@@b.com'], 'no domain' => ['a@'], 'no local' => ['@b.com'],
            'no top level domain' => ['a@b'], 'one letter top level domain' => ['a@b.c'], 'space inside' => ['a b@c.com'],
            'double dot' => ['a@b..com'], 'quoted local part' => ['"a b"@c.com'], 'ip literal' => ['a@[127.0.0.1]'],
            'trailing dot' => ['a@b.com.'], 'too long' => [str_repeat('a', 250) . '@b.com'], 'script' => ['<script>@x.com'],
            'comma' => ['a,b@c.com'], 'semicolon' => ['a@b.com;c@d.com'],
        ];
    }

    #[DataProvider('badEmails')]
    public function test_a_malformed_email_is_refused_and_nothing_is_saved(string $email): void
    {
        $before = $this->usersBefore();

        $this->register(['email' => $email])->assertSessionHasErrors('email');

        $this->assertSame($before, $this->usersBefore());
    }

    public function test_an_email_is_kept_in_lower_case_and_trimmed(): void
    {
        $this->register(['email' => "  Tendai.New@Example.COM \n"])->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('email', 'tendai.new@example.com')->first(), 'stored in lower case');
        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['tendai.new@example.com'])->count());
    }

    public function test_a_duplicate_email_is_refused_whatever_its_case(): void
    {
        $this->register()->assertSessionHasNoErrors();
        $before = $this->usersBefore();

        foreach (['tendai.new@example.com', 'TENDAI.NEW@EXAMPLE.COM', ' Tendai.New@example.com '] as $email) {
            $this->register(['email' => $email])->assertSessionHasErrors('email');
        }
        $this->registerProvider(['email' => 'TENDAI.NEW@example.com'])->assertSessionHasErrors('email');

        $this->assertSame($before, $this->usersBefore());
    }

    public function test_a_seeded_account_cannot_be_registered_again_in_another_case(): void
    {
        $before = $this->usersBefore();

        $this->register(['email' => strtoupper($this->student->email)])->assertSessionHasErrors('email');

        $this->assertSame($before, $this->usersBefore());
    }

    public function test_a_good_email_with_a_plus_tag_and_subdomain_is_accepted(): void
    {
        $this->register(['email' => 'tendai+scholar@mail.example.co.zw'])->assertSessionHasNoErrors();
    }

    public function test_the_admin_form_and_password_reset_hold_emails_to_the_same_rule(): void
    {
        $before = $this->usersBefore();

        $this->actingAs($this->admin)->post('/admin/users/create', [
            'full_name' => 'Rudo Moyo', 'email' => 'a@b', 'role_name' => 'ROLE_APPLICANT', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd',
        ])->assertSessionHasErrors('email');
        $this->assertSame($before, $this->usersBefore());

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->post('/forgot-password', ['email' => 'not an email'])->assertSessionHasErrors('email');
    }

    // =============================================================== 3. phones --

    public static function applicantPhones(): array
    {
        return [
            'ten digits' => ['0771234567', true], 'empty is allowed' => ['', true], 'nine digits' => ['077123456', false],
            'eleven digits' => ['07712345678', false], 'letters' => ['07712345ab', false], 'only letters' => ['abcdefghij', false],
            'spaces inside' => ['0771 234567', false], 'dashes' => ['077-123-4567', false], 'plus prefix' => ['+263771234567', false],
            'brackets' => ['(077)1234567', false], 'full-width digits' => ["\u{FF10}\u{FF17}\u{FF17}1234567", false], 'sql' => ["0771234567'; --", false],
            'a number as an array' => [['0771234567'], false],
        ];
    }

    #[DataProvider('applicantPhones')]
    public function test_the_applicants_phone_number(mixed $phone, bool $accepted): void
    {
        $before = $this->usersBefore();

        $response = $this->register(['phone' => $phone]);

        if ($accepted) {
            $response->assertSessionHasNoErrors();
            $stored = User::where('email', 'tendai.new@example.com')->firstOrFail()->phone;
            $this->assertSame($phone === '' ? null : $phone, $stored, 'a string, with its leading zero');
        } else {
            $response->assertSessionHasErrors('phone');
            $this->assertSame($before, $this->usersBefore());
        }
    }

    public function test_a_phone_number_with_a_trailing_line_break_is_not_accepted_by_the_rule_itself(): void
    {
        $this->assertSame(0, preg_match(\App\Support\FormOptions::PHONE_PATTERN, "0771234567\n"));
        $this->assertSame(1, preg_match(\App\Support\FormOptions::PHONE_PATTERN, '0771234567'));
    }

    public function test_the_phone_number_is_a_string_column(): void
    {
        $type = \Illuminate\Support\Facades\Schema::getColumnType('users', 'phone');

        $this->assertContains(strtolower($type), ['varchar', 'string', 'text', 'char'], 'phone numbers are identifiers, not quantities');
    }

    public static function providerPhones(): array
    {
        return [
            'zimbabwean landline' => ['+263242771234', true], 'local mobile' => ['0771234567', true], 'south african' => ['+27 11 123 4567', true],
            'us' => ['+1 202 555 0143', true], 'with extension' => ['+263242771234 ext 12', true], 'letters' => ['abc', false],
            'too short' => ['12345', false], 'country code only' => ['+263', false], 'script' => ['<script>', false], 'unassigned country' => ['+999999999', false],
            'mixed' => ['+263abc771234', false], 'letters read as keypad digits' => ['0771abc567', false], 'letters after the code' => ['+44 20 79ab 0000', false],
            'extension x' => ['+44 20 7946 0000 x12', true], 'letters in the extension' => ['+44 20 7946 0000 ext ab', false], 'array' => [['+263242771234'], false],
        ];
    }

    #[DataProvider('providerPhones')]
    public function test_a_providers_phone_number(mixed $phone, bool $accepted): void
    {
        $before = $this->usersBefore();

        $response = $this->registerProvider(['phone' => $phone]);

        if ($accepted) {
            $response->assertSessionHasNoErrors();
        } else {
            $response->assertSessionHasErrors('phone');
            $this->assertSame($before, $this->usersBefore());
        }
    }

    // ============================================================ 4. passwords --

    public static function passwords(): array
    {
        return [
            'strong' => ['Str0ngPassw0rd', true], 'too short' => ['Ab1', false], 'no digit' => ['Passwordpassword', false],
            'no capital' => ['password1234', false], 'no letter' => ['12345678', false], 'seven characters' => ['Abcde12', false],
            'with spaces and symbols' => ['Pass word 1 ! ?', true], 'very long' => [str_repeat('Aa1', 700), false],
        ];
    }

    #[DataProvider('passwords')]
    public function test_the_password_rules(string $password, bool $accepted): void
    {
        $before = $this->usersBefore();

        $response = $this->register(['password' => $password, 'password_confirmation' => $password]);

        $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('password');
        $this->assertSame($accepted ? $before + 1 : $before, $this->usersBefore());
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $this->register(['password_confirmation' => 'Different1Password'])->assertSessionHasErrors('password');
    }

    public function test_the_password_is_stored_hashed_never_as_typed(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $hash = User::where('email', 'tendai.new@example.com')->firstOrFail()->password_hash;

        $this->assertNotSame('Str0ngPassw0rd', $hash);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Str0ngPassw0rd', $hash));
    }

    // ======================================================= 5. required & types --

    public function test_every_registration_field_that_is_required_is_required(): void
    {
        foreach (['full_name', 'email', 'password'] as $field) {
            $before = $this->usersBefore();

            $this->register([$field => ''])->assertSessionHasErrors($field);
            $this->register([$field => null])->assertSessionHasErrors($field);
            $this->assertSame($before, $this->usersBefore(), $field);
        }
    }

    public function test_a_field_sent_as_the_wrong_type_is_refused_not_a_server_error(): void
    {
        foreach (['full_name', 'email', 'phone', 'password'] as $field) {
            $this->register([$field => ['a' => ['b']]])->assertSessionHasErrors($field);
            $this->register([$field => 12345])->assertStatus(302);
        }
    }

    public function test_extra_properties_cannot_set_protected_fields(): void
    {
        $this->register([
            'role_id' => 1, 'role_name' => 'Admin', 'is_admin' => true, 'account_status' => 'ACTIVE', 'email_verified' => true,
            'email_verified_at' => now()->toDateTimeString(), 'user_id' => 9999, 'password_hash' => 'x',
        ]);

        $user = User::where('email', 'tendai.new@example.com')->firstOrFail();

        $this->assertNotSame(9999, $user->user_id);
        $this->assertFalse((bool) $user->email_verified, 'verification cannot be claimed');
        $this->assertSame('ROLE_APPLICANT', $user->role()->value('role_name'), 'registering never makes an administrator');
    }

    // ================================================================= 6. dates --

    public static function birthDates(): array
    {
        return [
            'valid adult' => ['2000-05-17', true], 'today' => [null, false], 'tomorrow' => [null, false], 'before 1920' => ['1919-12-31', false],
            'impossible day' => ['2000-02-30', false], 'impossible month' => ['2000-13-01', false], 'words' => ['yesterday-ish', false],
            'wrong order' => ['17-05-2000x', false], 'array' => [['2000-01-01'], false], 'twelve years exactly is allowed' => [null, true],
            'under twelve' => [null, false],
        ];
    }

    public function test_the_date_of_birth(): void
    {
        $cases = [
            ['2000-05-17', true], [Carbon::today()->toDateString(), false], [Carbon::tomorrow()->toDateString(), false],
            ['1919-12-31', false], ['2000-02-30', false], ['2000-13-01', false], ['yesterday-ish', false], ['17-05-2000x', false],
            [Carbon::today()->subYears(5)->toDateString(), false], [Carbon::today()->subYears(30)->toDateString(), true],
        ];

        foreach ($cases as [$value, $accepted]) {
            $this->flushSession();
            $before = $this->student->fresh()->applicantProfile?->date_of_birth;

            $r = $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), ['full_name' => $this->student->full_name, 'gender' => 'male', 'date_of_birth' => $value]);

            $accepted ? $r->assertSessionDoesntHaveErrors('date_of_birth') : $r->assertSessionHasErrors('date_of_birth');
        }

        $this->flushSession();
        $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), ['full_name' => $this->student->full_name, 'gender' => 'male', 'date_of_birth' => ['2000-01-01']])
            ->assertSessionHasErrors('date_of_birth');
    }

    public function test_a_refused_date_of_birth_changes_nothing_on_the_profile(): void
    {
        $profile = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
        $before = $profile->only(['date_of_birth', 'biography']);

        $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), [
            'full_name' => $this->student->full_name, 'gender' => 'male', 'date_of_birth' => '2000-02-30', 'biography' => 'Changed alongside.',
        ])->assertSessionHasErrors('date_of_birth');

        $this->assertEquals($before, $profile->fresh()->only(['date_of_birth', 'biography']));
    }

    public function test_a_listing_deadline(): void
    {
        $cases = [
            [Carbon::today()->addDays(10)->toDateString(), true], [Carbon::today()->toDateString(), true], [Carbon::yesterday()->toDateString(), false],
            ['2000-01-01', false], ['2026-02-30', false], ['2026-13-40', false], ['next friday maybe', false], ['', true],
        ];

        foreach ($cases as $i => [$deadline, $accepted]) {
            $before = Opportunity::count();

            $r = $this->createListing(['title' => "Deadline case $i", 'deadline' => $deadline]);

            $accepted ? $r->assertSessionDoesntHaveErrors('deadline') : $r->assertSessionHasErrors('deadline');
            $this->assertSame($accepted ? $before + 1 : $before, Opportunity::count(), "deadline " . json_encode($deadline));
        }

        $this->createListing(['deadline' => ['2030-01-01']])->assertSessionHasErrors('deadline');
    }

    public function test_a_deadline_extension_must_move_forward_and_be_a_real_date(): void
    {
        $listing = $this->liveListing();
        $deadline = $listing->deadline->toDateString();

        foreach ([Carbon::yesterday()->toDateString(), 'garbage', '2026-02-30', $listing->deadline->copy()->subDay()->toDateString(), ''] as $bad) {
            $this->flushSession();
            $this->actingAs($this->provider)->post("/opportunities/{$listing->opportunity_id}/extend-deadline", ['deadline' => $bad, 'reason' => 'Because.']);
            $this->assertSame($deadline, $listing->fresh()->deadline->toDateString(), 'refused: ' . json_encode($bad));
        }
    }

    public function test_an_application_after_the_deadline_is_refused_by_the_server(): void
    {
        $closed = $this->liveListing(['deadline' => Carbon::today()->subDays(2)]);
        $before = Application::count();

        $this->actingAs($this->student->fresh())->post(route('applications.submit', $closed->opportunity_id), [
            'personal_statement' => str_repeat('I want to study. ', 10), 'confirm' => '1',
        ]);

        $this->assertSame($before, Application::count());
        $this->actingAs($this->student->fresh())->post(route('applications.quick', $closed->opportunity_id));
        $this->assertSame($before, Application::count());
    }

    // ================================================================ 7. numbers --

    public static function amounts(): array
    {
        return [
            'whole' => ['5000', true], 'two decimals' => ['1500.50', true], 'zero' => ['0', true], 'largest' => ['99999999', true],
            'above the largest' => ['100000000', false], 'negative' => ['-5', false], 'letters' => ['abc', false], 'with a currency sign' => ['$500', false],
            'comma' => ['1,500', false], 'exponent' => ['1e3', false], 'hex' => ['0x1A', false], 'plus sign' => ['+5', false],
            'three decimals' => ['1.234', false], 'trailing dot' => ['5.', false], 'leading dot' => ['.5', false], 'space inside' => ['5 000', false],
            'negative zero' => ['-0', false], 'infinity' => ['INF', false], 'array' => [['5'], false],
        ];
    }

    #[DataProvider('amounts')]
    public function test_the_award_amount(mixed $amount, bool $accepted): void
    {
        $before = Opportunity::count();

        $r = $this->createListing(['title' => 'Amount case', 'award_amount' => $amount, 'award_currency' => 'USD']);

        if ($accepted) {
            $r->assertSessionDoesntHaveErrors('award_amount');
            $this->assertSame($before + 1, Opportunity::count());
        } else {
            $r->assertSessionHasErrors('award_amount');
            $this->assertSame($before, Opportunity::count());
        }
    }

    public static function slots(): array
    {
        return [
            'one' => ['1', true], 'the most' => ['5000', true], 'zero' => ['0', false], 'negative' => ['-1', false], 'above the most' => ['5001', false],
            'a decimal' => ['2.5', false], 'letters' => ['many', false], 'exponent' => ['1e2', false], 'plus sign' => ['+3', false], 'array' => [['3'], false],
        ];
    }

    #[DataProvider('slots')]
    public function test_the_number_of_awards(mixed $slots, bool $accepted): void
    {
        $r = $this->createListing(['title' => 'Slots case', 'award_slots' => $slots]);

        $accepted ? $r->assertSessionDoesntHaveErrors('award_slots') : $r->assertSessionHasErrors('award_slots');
    }

    public function test_the_age_limit_and_the_points_minimum(): void
    {
        foreach ([['max_age', '9', false], ['max_age', '25', true], ['max_age', '99', true], ['max_age', '100', false], ['max_age', '25.5', false], ['max_age', 'young', false],
            ['min_academic_points', '0', false], ['min_academic_points', '-3', false], ['min_academic_points', '5', true], ['min_academic_points', '9999', false], ['min_academic_points', '1e1', false]] as [$field, $value, $accepted]) {
            $r = $this->createListing(['title' => "$field $value", 'education_level' => EducationLevel::UNDERGRADUATE, $field => $value]);

            $accepted ? $r->assertSessionDoesntHaveErrors($field) : $r->assertSessionHasErrors($field);
        }
    }

    public function test_a_year_on_a_subject_result_is_a_real_year(): void
    {
        foreach ([['1949', false], ['1950', true], [(string) (now()->year + 1), true], [(string) (now()->year + 2), false], ['20x4', false], ['2024.5', false], ['-2024', false], ['', true]] as [$year, $accepted]) {
            $this->flushSession();
            $r = $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), [
                'full_name' => $this->student->full_name, 'gender' => 'male', 'academic_results_submitted' => '1',
                'academic_subject_results' => [0 => ['qualification_id' => 1, 'subject_id' => 1, 'result' => 'A', 'year' => $year]],
            ]);

            $accepted ? $r->assertSessionDoesntHaveErrors('academic_subject_results.0.year') : $r->assertSessionHasErrors('academic_subject_results.0.year');
        }
    }

    // ============================================================ 8. text and urls --

    public function test_listing_text_has_sensible_limits_and_is_trimmed(): void
    {
        $this->createListing(['title' => ''])->assertSessionHasErrors('title');
        $this->createListing(['title' => '    '])->assertSessionHasErrors('title');
        $this->createListing(['title' => str_repeat('a', 256)])->assertSessionHasErrors('title');
        $this->createListing(['description' => str_repeat('a', 10001)])->assertSessionHasErrors('description');
        $this->createListing(['description' => ''])->assertSessionHasErrors('description');
        $this->createListing(['title' => ['x']])->assertSessionHasErrors('title');

        $this->createListing(['title' => "  Padded Title \n"])->assertSessionHasNoErrors();
        $this->assertNotNull(Opportunity::where('title', 'Padded Title')->first(), 'stored trimmed');
    }

    public function test_the_external_link_is_a_web_address_only(): void
    {
        foreach ([['https://example.com/apply?x=1', true], ['http://example.com', true], ['ftp://example.com/file', false], ['javascript:alert(1)', false],
            ['data:text/html,x', false], ['example.com', false], ['//evil.com', false], ['https://', false], ['https://exa mple.com', false],
            ['file:///etc/passwd', false], [str_repeat('a', 501), false], ['mailto:a@b.com', false]] as [$url, $accepted]) {
            $before = Opportunity::count();

            $r = $this->createListing(['title' => 'Link ' . md5($url), 'external_url' => $url]);

            $accepted ? $r->assertSessionDoesntHaveErrors('external_url') : $r->assertSessionHasErrors('external_url');
            $this->assertSame($accepted ? $before + 1 : $before, Opportunity::count(), $url);
        }
    }

    public function test_markup_in_text_is_shown_as_text_not_run(): void
    {
        $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
        $listing = $this->liveListing(['title' => 'Escape ' . $payload, 'description' => $payload]);

        $detail = $this->actingAs($this->student->fresh())->get(route('scholarships.show', $listing->opportunity_id))->assertOk()->getContent();
        $search = $this->get(route('scholarships.index', ['keyword' => 'Escape']))->assertOk()->getContent();

        foreach ([$detail, $search] as $html) {
            $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
            $this->assertStringNotContainsString('<img src=x onerror', $html);
        }
        $this->assertStringContainsString('&lt;script&gt;', $detail);
    }

    public function test_a_biography_and_statement_are_escaped_where_a_provider_reads_them(): void
    {
        $payload = '<script>alert("bio")</script>';
        ApplicantProfile::where('user_id', $this->student->user_id)->update(['biography' => $payload]);
        $listing = $this->liveListing();
        $application = Application::create([
            'user_id' => $this->student->user_id, 'opportunity_id' => $listing->opportunity_id, 'application_status' => 'PENDING',
            'personal_statement' => str_repeat('x', 100) . $payload, 'submitted_at' => now(),
        ]);

        $html = $this->actingAs($this->provider->fresh())->get(route('provider.applications.show', $application->application_id))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert("bio")</script>', $html);
    }

    // =============================================================== 9. choices --

    public function test_a_value_that_is_not_one_of_the_options_is_refused(): void
    {
        $profile = fn (array $d) => $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), array_merge(['full_name' => $this->student->full_name, 'gender' => 'male'], $d));

        foreach ([['gender', 'robot'], ['gender', ['male']], ['province', 'Atlantis'], ['education_level', 'WIZARD'], ['settlement_type', 'moon']] as [$field, $value]) {
            $this->flushSession();
            $profile([$field => $value])->assertSessionHasErrors($field);
        }

        foreach ([['funding_type', 'Free money'], ['country', 'Atlantis'], ['education_level', 'WIZARD'], ['award_currency', 'XXX'], ['required_province', 'Nowhere'], ['target_settlement_type', 'moon']] as [$field, $value]) {
            $before = Opportunity::count();
            $this->createListing(['title' => "Bad $field", $field => $value])->assertSessionHasErrors($field);
            $this->assertSame($before, Opportunity::count(), $field);
        }

        $this->flushSession();
        $this->actingAs($this->admin)->post('/admin/users/create', [
            'full_name' => 'Rudo Moyo', 'email' => 'role.bad@example.com', 'role_name' => 'SuperUser', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd',
        ])->assertSessionHasErrors('role_name');
        $this->assertNull(User::where('email', 'role.bad@example.com')->first());
    }

    public function test_a_decision_must_be_one_of_the_two_allowed(): void
    {
        $listing = $this->liveListing();
        $application = Application::create([
            'user_id' => $this->student->user_id, 'opportunity_id' => $listing->opportunity_id, 'application_status' => 'PENDING',
            'personal_statement' => str_repeat('x', 120), 'submitted_at' => now(),
        ]);

        foreach (['ACCEPTED_NOW', 'PENDING', 'WITHDRAWN', '', ['ACCEPTED']] as $bad) {
            $this->flushSession();
            $this->actingAs($this->provider->fresh())->post(route('provider.applications.review', $application->application_id), ['status' => $bad, 'reason' => 'Because.']);
            $this->assertSame('PENDING', $application->fresh()->application_status, json_encode($bad));
        }
    }

    // =========================================================== 10. the statement --

    public function test_the_personal_statement_lengths(): void
    {
        $listing = $this->liveListing();
        $go = fn (mixed $statement, array $more = []) => $this->actingAs($this->student->fresh())
            ->post(route('applications.submit', $listing->opportunity_id), array_merge(['personal_statement' => $statement, 'confirm' => '1'], $more));
        $count = fn () => Application::where('user_id', $this->student->user_id)->where('opportunity_id', $listing->opportunity_id)->count();

        $this->flushSession();
        $go(str_repeat('a', 99))->assertSessionHasErrors('personal_statement');
        $this->assertSame(0, $count());

        $this->flushSession();
        $go(str_repeat('a', 5001))->assertSessionHasErrors('personal_statement');
        $go('   ' . str_repeat(' ', 120))->assertSessionHasErrors('personal_statement');
        $go(['x'])->assertSessionHasErrors('personal_statement');
        $this->assertSame(0, $count());

        $this->flushSession();
        $go(str_repeat('a', 100), ['confirm' => ''])->assertSessionHasErrors('confirm');
        $this->assertSame(0, $count());
    }

    public function test_one_application_per_scholarship_however_often_it_is_sent(): void
    {
        $listing = $this->liveListing();
        $send = fn () => $this->actingAs($this->student->fresh())->post(route('applications.submit', $listing->opportunity_id), ['personal_statement' => str_repeat('I want to study. ', 10), 'confirm' => '1']);

        $send();
        $send();
        $send();

        $this->assertLessThanOrEqual(1, Application::where('user_id', $this->student->user_id)->where('opportunity_id', $listing->opportunity_id)->count());
    }

    // ============================================================== 11. uploads --

    public static function badUploads(): array
    {
        return [
            'a php script named .pdf' => ['cv.pdf', "<?php echo 'x'; ?>"],
            'an html page named .pdf' => ['cv.pdf', '<html><script>alert(1)</script></html>'],
            'an exe named .pdf' => ['cv.pdf', "MZ\x90\x00\x03\x00\x00\x00"],
            'a real php file' => ['shell.php', "<?php system(\$_GET['c']); ?>"],
            'double extension' => ['cv.php.pdf', "<?php echo 1; ?>"],
            'a shell script' => ['run.sh', "#!/bin/sh\nrm -rf /\n"],
            'an svg with a script' => ['pic.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'an empty file' => ['empty.pdf', ''],
        ];
    }

    #[DataProvider('badUploads')]
    public function test_an_unsafe_or_empty_document_is_refused_and_nothing_is_stored(string $name, string $body): void
    {
        $disk = Storage::disk((string) config('filesystems.default', 'local'));
        $profile = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
        $before = $profile->only(['cv_path', 'cv_filename']);

        $this->actingAs($this->student->fresh())->post(route('applicant.profile.documents', 'cv'), ['document' => $this->realFile($name, $body)])
            ->assertSessionHasErrors('document');

        $this->assertEquals($before, $profile->fresh()->only(['cv_path', 'cv_filename']));
        $this->assertSame([], $disk->allFiles(), 'nothing was written');
    }

    public function test_a_document_over_the_size_limit_is_refused(): void
    {
        $this->actingAs($this->student->fresh())->post(route('applicant.profile.documents', 'cv'), [
            'document' => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf'),
        ])->assertSessionHasErrors('document');
    }

    public function test_a_document_type_that_does_not_exist_is_a_404(): void
    {
        foreach (['nothing', '../etc/passwd', 'cv%00', str_repeat('a', 300)] as $type) {
            $this->actingAs($this->student->fresh())->post('/applicant/profile/documents/' . rawurlencode($type), ['document' => $this->pdf()])->assertStatus(404);
        }
    }

    public function test_a_good_document_is_accepted_stored_privately_under_a_name_the_uploader_did_not_choose(): void
    {
        $disk = Storage::disk((string) config('filesystems.default', 'local'));

        foreach (['cv.pdf', '../../evil.pdf', "weird\r\nname.pdf", 'résumé final (1).pdf'] as $name) {
            $this->flushSession();
            $this->actingAs($this->student->fresh())->post(route('applicant.profile.documents', 'cv'), ['document' => $this->pdf($name)])->assertSessionHasNoErrors();

            $path = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail()->cv_path;
            $this->assertNotNull($path, $name);
            $this->assertStringNotContainsString('..', $path);
            $this->assertStringNotContainsString("\n", $path);
            $this->assertTrue($disk->exists($path));
        }
    }

    public function test_the_provider_registration_document_is_held_to_the_same_rules(): void
    {
        $before = $this->usersBefore();

        $this->registerProvider(['certificate' => $this->realFile('cert.pdf', "<?php echo 1; ?>")])->assertSessionHasErrors('certificate');
        $this->registerProvider(['certificate' => $this->realFile('cert.exe', "MZ ")])->assertSessionHasErrors('certificate');
        $this->registerProvider(['certificate' => $this->realFile('cert.pdf', '')])->assertSessionHasErrors('certificate');
        $this->registerProvider(['certificate' => ''])->assertSessionHasErrors('certificate');
        $this->registerProvider(['certificate' => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')])->assertSessionHasErrors('certificate');

        $this->assertSame($before, $this->usersBefore());
    }

    // ===================================================== 12. search and paging --

    public static function hostileQueries(): array
    {
        return [
            'keyword as an array' => [['keyword' => ['a', 'b']]], 'keyword nested' => [['keyword' => ['a' => ['b' => 'c']]]],
            'country as an array' => [['country' => ['Zimbabwe']]], 'province as an array' => [['province' => ['Harare']]],
            'sort as an array' => [['sort' => ['title']]], 'sort unknown' => [['sort' => 'DROP TABLE']],
            'page letters' => [['page' => 'abc']], 'page negative' => [['page' => '-5']], 'page zero' => [['page' => '0']],
            'page huge' => [['page' => '99999999999999999999']], 'page as an array' => [['page' => ['1']]],
            'deadline garbage' => [['deadline_before' => 'garbage']], 'deadline array' => [['deadline_before' => ['2030-01-01']]],
            'min_award garbage' => [['min_award' => 'lots']], 'min_award array' => [['min_award' => ['5']]], 'min_award exponent' => [['min_award' => '1e999']],
            'a very long keyword' => [['keyword' => str_repeat('a', 20000)]], 'sql in keyword' => [['keyword' => "' OR '1'='1"]],
            'wildcards' => [['keyword' => '%_\\']], 'a null byte' => [['keyword' => "a\0b"]], 'emoji' => [['keyword' => "\u{1F600}\u{1F600}"]],
            'renewable as text' => [['renewable_only' => 'banana']], 'renewable as array' => [['renewable_only' => ['1']]],
        ];
    }

    #[DataProvider('hostileQueries')]
    public function test_a_hostile_search_query_never_causes_a_server_error(array $query): void
    {
        $response = $this->get(route('scholarships.index', $query));

        $this->assertLessThan(500, $response->getStatusCode(), json_encode($query));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('Stack trace', $response->getContent());
    }

    public function test_a_hostile_query_on_the_signed_in_pages_never_causes_a_server_error(): void
    {
        foreach (['/applicant/recommendations', '/my-applications', '/applicant/saved', '/notifications', '/provider/dashboard', '/provider/applications', '/admin/users', '/admin/audit-log', '/admin/search', '/admin/opportunities'] as $uri) {
            $actor = str_starts_with($uri, '/admin') ? $this->admin : (str_starts_with($uri, '/provider') ? $this->provider : $this->student);

            foreach ([['page' => 'abc'], ['page' => ['1']], ['q' => ['a']], ['q' => str_repeat('z', 30000)], ['status' => ['x']], ['per_page' => '100000'], ['sort' => ['a']], ['search' => ["a\0b"]], ['role' => ['Admin']]] as $query) {
                $response = $this->actingAs($actor->fresh())->get($uri . '?' . http_build_query($query));

                $this->assertLessThan(500, $response->getStatusCode(), "$uri " . json_encode($query));
            }
        }
    }

    public function test_the_page_size_cannot_be_chosen_by_the_requester(): void
    {
        $this->liveListing();

        $html = $this->get(route('scholarships.index', ['per_page' => 100000, 'perPage' => 100000, 'limit' => 100000]))->assertOk()->getContent();

        $this->assertLessThan(200000, strlen($html), 'a page is a page, not the whole table');
    }

    // ===================================================== 13. who may do what --

    public function test_a_visitor_cannot_use_any_form_that_needs_an_account(): void
    {
        foreach (['/applicant/profile', '/opportunities/create', '/apply/1', '/apply/1/quick', '/account/password', '/admin/users/create', '/provider/website', '/scholarships/1/report', '/applicant/profile/programmes'] as $uri) {
            $this->flushSession();
            $response = $this->post($uri, ['x' => 'y']);

            $this->assertContains($response->getStatusCode(), [302, 401, 403, 404, 405, 419], $uri);
            if ($response->isRedirect()) {
                $this->assertStringContainsString('/login', (string) $response->headers->get('Location'), $uri);
            }
        }
    }

    public function test_a_user_cannot_touch_another_users_application_or_listing(): void
    {
        $listing = $this->liveListing();
        $other = User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail();
        $application = Application::create([
            'user_id' => $other->user_id, 'opportunity_id' => $listing->opportunity_id, 'application_status' => 'PENDING',
            'personal_statement' => str_repeat('x', 120), 'submitted_at' => now(),
        ]);

        $this->actingAs($this->student->fresh())->post(route('applications.withdraw', $application->application_id), ['reason' => 'x']);
        $this->assertSame('PENDING', $application->fresh()->application_status, 'another applicant cannot withdraw it');

        $otherProvider = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $this->flushSession();
        $this->actingAs($otherProvider->fresh())->post(route('provider.applications.review', $application->application_id), ['status' => 'ACCEPTED', 'reason' => 'Mine now.']);
        $this->assertSame('PENDING', $application->fresh()->application_status, 'another provider cannot decide it');

        $this->flushSession();
        $this->actingAs($otherProvider->fresh())->put("/opportunities/{$listing->opportunity_id}", $this->listingPayload(['title' => 'Hijacked', 'reason' => 'x']));
        $this->assertNotSame('Hijacked', $listing->fresh()->title);

        $this->flushSession();
        $this->actingAs($this->student->fresh())->post(route('provider.applications.review', $application->application_id), ['status' => 'ACCEPTED', 'reason' => 'Mine.'])->assertStatus(403);
    }

    public function test_an_applicant_cannot_change_protected_profile_fields_by_adding_them(): void
    {
        $profile = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
        $before = $profile->only(['user_id', 'profile_id']);

        $this->actingAs($this->student->fresh())->post(route('applicant.profile.update'), [
            'full_name' => $this->student->full_name, 'gender' => 'male', 'user_id' => 999, 'profile_id' => 999, 'role_id' => 1, 'email' => 'taken.over@example.com',
            'account_status' => 'SUSPENDED', 'results_certificate_path' => '../../etc/passwd', 'cv_path' => 'profiles/other/cv.pdf',
        ]);

        $this->assertEquals($before, $profile->fresh()->only(['user_id', 'profile_id']));
        $this->assertNotSame('taken.over@example.com', $this->student->fresh()->email);
        $this->assertNotSame('../../etc/passwd', $profile->fresh()->results_certificate_path);
        $this->assertNotSame('profiles/other/cv.pdf', $profile->fresh()->cv_path);
    }

    // ===================================== 14. every write route, hostile input --

    /** @return array<string, mixed> */
    private function hostileKeys(): array
    {
        return array_fill_keys([
            'full_name', 'email', 'phone', 'password', 'password_confirmation', 'current_password', 'confirm_email', 'title', 'description', 'country',
            'education_level', 'minimum_education_level', 'funding_type', 'deadline', 'award_amount', 'award_currency', 'award_slots', 'external_url',
            'min_academic_points', 'max_age', 'required_province', 'target_locality', 'subject_requirements', 'scope_programmes', 'scope_fields',
            'scope_institutions', 'open_to', 'reason', 'note', 'status', 'decision', 'personal_statement', 'confirm', 'document', 'documents', 'profile',
            'date_of_birth', 'gender', 'province', 'locality', 'biography', 'guardian_name', 'guardian_phone', 'academic_subject_results',
            'current_programme_id', 'intended_programme_ids', 'target_field_ids', 'applied_institution_ids', 'opportunities', 'role_name', 'name',
            'code', 'type', 'kind', 'file', 'value', 'field_id', 'parent_id', 'institutions', 'synonyms', 'website', 'contact_name', 'contact_role',
            'organisation_type', 'registration_country', 'registration_number', 'details', 'token', 'page', 'q', 'sort',
        ], null);
    }

    public static function hostileValues(): array
    {
        return [
            'nested arrays' => [fn () => ['x' => ['y' => ['z' => 1]]]],
            'a very long string' => [fn () => str_repeat('A', 10500)],
            'a null byte' => [fn () => "a\0b"],
            'emoji and control characters' => [fn () => "\u{1F600} \u{202E}\u{0301} \x07"],
            'exponent and overflow numbers' => [fn () => '1e999'],
            'script and sql' => [fn () => "' OR 1=1; DROP TABLE users;-- <script>alert(1)</script>"],
            'negative and huge integers' => [fn () => -99999999999999999999],
            'booleans' => [fn () => true],
        ];
    }

    #[DataProvider('hostileValues')]
    public function test_no_write_route_fails_with_a_server_error_on_hostile_input(callable $value): void
    {
        $payload = array_map(fn () => $value(), $this->hostileKeys());
        $failures = [];

        foreach (app('router')->getRoutes() as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD', 'GET', 'OPTIONS']));

            if ($methods === [] || str_starts_with($route->uri(), '_') || str_starts_with($route->uri(), 'storage') || str_contains($route->uri(), 'logout')) {
                continue;
            }

            $uri = $route->uri();
            $actor = match (true) {
                str_starts_with($uri, 'admin') => $this->admin,
                str_starts_with($uri, 'provider'), str_starts_with($uri, 'opportunities') => $this->provider,
                default => $this->student,
            };

            foreach ([1, 999999999] as $id) {
                $path = '/' . preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => str_contains(strtolower($m[1]), 'type') ? 'cv' : (str_contains(strtolower($m[1]), 'token') ? 'tok' : (string) $id), $uri);

                foreach ([$actor, null] as $who) {
                    $this->flushSession();
                    $who === null ? $this->app['auth']->forgetGuards() : $this->actingAs($who->fresh());

                    try {
                        $response = $this->call($methods[0] === 'PUT' || $methods[0] === 'DELETE' ? $methods[0] : 'POST', $path, $payload);
                    } catch (\Throwable $e) {
                        $failures[] = "$methods[0] $path as " . ($who?->email ?? 'visitor') . ': ' . class_basename($e) . ' - ' . substr($e->getMessage(), 0, 120);

                        continue;
                    }

                    if ($response->getStatusCode() >= 500) {
                        $failures[] = "$methods[0] $path as " . ($who?->email ?? 'visitor') . ': HTTP ' . $response->getStatusCode();
                    } elseif (preg_match('/SQLSTATE|Stack trace|vendor\\\\laravel|Illuminate\\\\/', $response->getContent())) {
                        $failures[] = "$methods[0] $path: internals in the response";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($failures)), "Routes that failed on this input:\n" . implode("\n", array_unique($failures)));
    }
}
