<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ProviderOrgType;
use App\Support\RoleNames;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A provider's own work/organisation phone number: validated by
 * App\Rules\InternationalPhoneNumber, not FormOptions::PHONE_PATTERN - see
 * that rule's docblock for why a provider needs international, landline,
 * and extension support that an applicant's Zimbabwe-only mobile number
 * never did. InputValidationHardeningTest covers the applicant/guardian
 * rule, which this file does not touch or duplicate.
 */
class ProviderPhoneValidationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function register(?string $phone): TestResponse
    {
        $this->sequence++;
        Storage::fake('local');

        return $this->post('/register/provider', [
            'full_name' => 'Chikafu Education Trust',
            'email' => "trust{$this->sequence}@example.test",
            'phone' => $phone,
            'organisation_type' => ProviderOrgType::ALL[0],
            'certificate' => UploadedFile::fake()->create('registration.pdf', 40, 'application/pdf'),
            'password' => 'ChangeMe123',
            'password_confirmation' => 'ChangeMe123',
            'authorised' => '1',
        ]);
    }

    public static function validNumbers(): array
    {
        return [
            'Zimbabwe mobile, with country code' => ['+263771234567'],
            'Zimbabwe mobile, local format' => ['0771234567'],
            'Zimbabwe landline, with country code' => ['+263242771234'],
            'Zimbabwe landline, local format' => ['0242771234'],
            'South African international landline' => ['+27111234567'],
            'UK international landline' => ['+442071234567'],
            'US international mobile' => ['+12125550198'],
            'with a spelled-out extension' => ['+263242771234 ext. 205'],
            'with a short x-style extension' => ['+263242771234 x205'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_a_valid_provider_number_is_accepted(string $phone): void
    {
        $this->register($phone)->assertSessionHasNoErrors('phone');
    }

    public static function invalidNumbers(): array
    {
        return [
            'too short to be any real number' => ['123'],
            // Not "all letters" or "letters mixed with digits" - libphonenumber
            // correctly treats those as vanity numbers (e.g. "077abc4567" reads
            // as a real mobile number via each letter's keypad digit, the same
            // way "1-800-FLOWERS" does) and that is a real phone number format,
            // not a rule gap. A real-looking but non-existent number is what
            // genuinely fails the numbering-plan check.
            'not a real, allocated number' => ['0000000000'],
            'a country code with no digits after it' => ['+263'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_an_invalid_provider_number_is_rejected(string $phone): void
    {
        $this->register($phone)->assertSessionHasErrors('phone');
    }

    /** The whole point of this rule: +263 is never required, unlike the old shared pattern's implicit Zimbabwe-only assumption. */
    public function test_263_is_not_mandatory_for_a_provider_number(): void
    {
        $this->register('0771234567')->assertSessionHasNoErrors('phone');
    }

    /**
     * The form itself must not block what the server accepts. The strict
     * applicant markup (10 digits, maxlength 10) used to sit on this field, so
     * a landline, "+263" or an extension never reached the server at all.
     */
    public function test_the_provider_form_does_not_carry_the_applicants_ten_digit_markup(): void
    {
        $html = $this->get('/register/provider')->assertOk()->getContent();

        preg_match('/<input[^>]*name="phone"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m, 'the phone input must render');
        $this->assertStringNotContainsString('maxlength="10"', $m[0]);
        $this->assertStringNotContainsString('minlength', $m[0]);
        $this->assertStringNotContainsString('pattern=', $m[0]);
        $this->assertStringNotContainsString('inputmode="numeric"', $m[0]);
        $this->assertStringContainsString('landline', $html);
    }

    /** The applicant form keeps its strict markup. */
    public function test_the_applicant_form_keeps_its_ten_digit_markup(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        preg_match('/<input[^>]*name="phone"[^>]*>/', $html, $m);
        $this->assertStringContainsString('maxlength="10"', $m[0]);
    }

    /** Landline, country-coded and extension numbers all register, not just a mobile. */
    #[DataProvider('landlineNumbers')]
    public function test_a_provider_can_register_with_a_landline_style_number(string $phone): void
    {
        $this->register($phone)->assertSessionHasNoErrors('phone');
    }

    public static function landlineNumbers(): array
    {
        return [
            'harare landline' => ['0242700000'],
            'spaced landline' => ['024 2700000'],
            'country code' => ['+263 242 700000'],
            'bulawayo' => ['0292 880000'],
            'extension' => ['0242700000 x123'],
        ];
    }

    public function test_phone_remains_optional_for_a_provider(): void
    {
        $this->register(null)->assertSessionHasNoErrors('phone');
    }

    /** The admin-created-account path applies the same provider-specific rule, mirroring the name-rule split already there. */
    public function test_an_admin_creating_a_provider_account_gets_the_same_relaxed_phone_rule(): void
    {
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($admin)->post('/admin/users/create', [
            'full_name' => 'Midlands Community Trust',
            'email' => 'midlands-trust@example.test',
            'phone' => '+442071234567',
            'role_name' => RoleNames::PROVIDER,
            'password' => 'ChangeMe123',
            'password_confirmation' => 'ChangeMe123',
        ])->assertSessionHasNoErrors('phone');
    }

    /** The same admin path still applies the strict Zimbabwe-only rule for a non-provider account. */
    public function test_an_admin_creating_an_applicant_account_keeps_the_strict_phone_rule(): void
    {
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($admin)->post('/admin/users/create', [
            'full_name' => 'Tendai Moyo',
            'email' => 'tendai-moyo@example.test',
            'phone' => '+442071234567',
            'role_name' => RoleNames::APPLICANT,
            'password' => 'ChangeMe123',
            'password_confirmation' => 'ChangeMe123',
        ])->assertSessionHasErrors('phone');
    }
}
