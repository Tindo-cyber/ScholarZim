<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\EducationLevel;
use App\Support\RoleNames;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two field-level rules, applied only where the field's own meaning calls
 * for them: a person's name never carries a digit, and a Zimbabwean phone
 * number is exactly ten digits and nothing else - see
 * FormOptions::NAME_PATTERN and FormOptions::PHONE_PATTERN, the one
 * definition every route below shares.
 *
 * Deliberately excluded: provider registration's own `full_name`, which
 * this product uses as an organisation or contact name ("Chikafu Education
 * Trust") rather than a person's name - see the comment on that validation
 * rule in RegisterController::registerProvider().
 */
class InputValidationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // ------------------------------------------------------------- names --

    public function test_a_full_name_with_numbers_is_rejected_on_registration(): void
    {
        $this->post('/register', $this->registerFields(['full_name' => 'John123']))
            ->assertSessionHasErrors('full_name');

        $this->assertNull(User::where('email', 'john123@example.test')->first());
    }

    public function test_a_purely_numeric_full_name_is_rejected_on_registration(): void
    {
        $this->post('/register', $this->registerFields(['full_name' => '123456']))
            ->assertSessionHasErrors('full_name');
    }

    public function test_a_normal_full_name_is_accepted_on_registration(): void
    {
        $this->post('/register', $this->registerFields(['full_name' => 'Jane Moyo']))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('email', 'jane-moyo@example.test')->first());
    }

    /** Apostrophes and hyphens are ordinary name punctuation, not an exception. */
    public function test_a_hyphenated_apostrophe_name_is_accepted_on_registration(): void
    {
        $this->post('/register', $this->registerFields([
            'full_name' => "Anne-Marie O'Brien",
            'email' => 'anne-marie@example.test',
        ]))->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('email', 'anne-marie@example.test')->first());
    }

    /**
     * Deliberately not letters-only: this field is an organisation or
     * contact name for a provider ("Chikafu Education Trust" is the seeded
     * example), and applying the person-name rule here would reject a
     * legitimate provider's own name.
     */
    public function test_provider_registration_full_name_is_not_restricted_to_letters(): void
    {
        $this->post('/register/provider', $this->registerProviderFields([
            'full_name' => '3 Rivers Education Trust',
        ]))->assertSessionDoesntHaveErrors('full_name');
    }

    public function test_guardian_name_with_numbers_is_rejected_on_the_profile_form(): void
    {
        $pupil = $this->primaryPupil();

        $this->actingAs($pupil)
            ->post('/applicant/profile', $this->primaryProfileFields(['guardian_name' => 'Grace123']))
            ->assertSessionHasErrors('guardian_name');
    }

    public function test_a_normal_guardian_name_is_accepted_on_the_profile_form(): void
    {
        $pupil = $this->primaryPupil();

        $this->actingAs($pupil)
            ->post('/applicant/profile', $this->primaryProfileFields(['guardian_name' => 'Grace Marufu']))
            ->assertSessionHasNoErrors();
    }

    public function test_an_admin_created_users_full_name_with_numbers_is_rejected(): void
    {
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->adminUserFields(['full_name' => 'Provider99']))
            ->assertSessionHasErrors('full_name');
    }

    public function test_an_admin_created_users_normal_full_name_is_accepted(): void
    {
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->adminUserFields())
            ->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('email', 'new-admin-user@example.test')->first());
    }

    // ------------------------------------------------------------ phones --

    public function test_ten_digits_is_accepted(): void
    {
        $this->post('/register', $this->registerFields(['phone' => '0712345678']))
            ->assertSessionHasNoErrors();
    }

    public function test_nine_digits_is_rejected(): void
    {
        $this->post('/register', $this->registerFields(['phone' => '071234567']))
            ->assertSessionHasErrors('phone');
    }

    public function test_eleven_digits_is_rejected(): void
    {
        $this->post('/register', $this->registerFields(['phone' => '07123456789']))
            ->assertSessionHasErrors('phone');
    }

    public function test_letters_in_the_phone_number_are_rejected(): void
    {
        $this->post('/register', $this->registerFields(['phone' => '07123abc78']))
            ->assertSessionHasErrors('phone');
    }

    public function test_dashes_in_the_phone_number_are_rejected(): void
    {
        $this->post('/register', $this->registerFields(['phone' => '0712-345-678']))
            ->assertSessionHasErrors('phone');
    }

    /** The international format is explicitly out of scope - exactly ten digits, no country code. */
    public function test_an_international_format_phone_number_is_rejected(): void
    {
        $this->post('/register', $this->registerFields(['phone' => '+263771234567']))
            ->assertSessionHasErrors('phone');
    }

    public function test_phone_remains_optional(): void
    {
        $fields = $this->registerFields();
        unset($fields['phone']);

        $this->post('/register', $fields)->assertSessionHasNoErrors();
    }

    public function test_guardian_phone_must_also_be_exactly_ten_digits(): void
    {
        $pupil = $this->primaryPupil();

        $this->actingAs($pupil)
            ->post('/applicant/profile', $this->primaryProfileFields(['guardian_phone' => '+263 772 111 222']))
            ->assertSessionHasErrors('guardian_phone');

        $this->actingAs($pupil)
            ->post('/applicant/profile', $this->primaryProfileFields(['guardian_phone' => '0772111222']))
            ->assertSessionHasNoErrors();
    }

    // -------------------------------------------------------------- login --

    /** Confirms no code change was needed here - this is a fixed baseline, not a new rule. */
    public function test_login_rejects_an_invalid_email_format(): void
    {
        $this->post('/login', ['email' => 'not-an-email', 'password' => 'Whatever123'])
            ->assertSessionHasErrors('email');
    }

    public function test_login_accepts_a_well_formed_email_and_judges_only_the_credentials(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        // Right shape, wrong password: refused for being wrong, not for its shape.
        $response = $this->post('/login', ['email' => $student->email, 'password' => 'NotThePassword1']);

        $response->assertSessionHasErrors('email');
        $response->assertSessionDoesntHaveErrors(['email' => 'The email field must be a valid email address.']);
    }

    /** The name/phone patterns must never reach the password field. */
    public function test_login_does_not_restrict_password_characters(): void
    {
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => "P@ss-w0rd 123!"])
            ->assertSessionDoesntHaveErrors('password');
    }

    // --------------------------------------------------------------- helpers --

    private function registerFields(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Jane Moyo',
            'email' => 'jane-moyo@example.test',
            'phone' => '0712345678',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms' => '1',
        ], $overrides);
    }

    private function registerProviderFields(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'A New Trust',
            'email' => 'a-new-trust@example.test',
            'phone' => '0712345678',
            'organisation_type' => 'Trust',
            'registration_number' => 'TR-2027-001',
            'certificate' => \Illuminate\Http\UploadedFile::fake()->create('certificate.pdf', 80, 'application/pdf'),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms' => '1',
        ], $overrides);
    }

    private function adminUserFields(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'New Admin User',
            'email' => 'new-admin-user@example.test',
            'phone' => '0712345678',
            'role_name' => RoleNames::APPLICANT,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    private function primaryPupil(): User
    {
        $applicant = User::create([
            'role_id' => Role::where('role_name', RoleNames::APPLICANT)->value('role_id'),
            'full_name' => 'Validation Test Pupil',
            'email' => 'validation-pupil@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);

        ApplicantProfile::create([
            'user_id' => $applicant->user_id,
            'education_level' => EducationLevel::PRIMARY,
        ]);

        return $applicant;
    }

    private function primaryProfileFields(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Validation Test Pupil',
            'education_level' => EducationLevel::PRIMARY,
            'province' => 'Harare',
            'guardian_name' => 'A Guardian',
            'guardian_phone' => '0771000000',
            'guardian_relationship' => 'Mother',
        ], $overrides);
    }
}
