<?php

namespace Tests\Feature;

use App\Models\ProviderProfile;
use App\Models\User;
use App\Support\ProviderOrgType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What registration does and does not ask for.
 *
 * Retired: the applicant "terms of use and privacy policy" checkbox (there is
 * no terms or privacy page for it to point at).
 *
 * Restored: the provider registration number. It was retired when every
 * provider was assumed to be Zimbabwean and the certificate alone was checked.
 * With organisations registering from any country it is asked again - any
 * format accepted - and an organisation without one explains why instead, so
 * the administrator has something to check the document against either way.
 *
 * Kept: a provider still confirms they are registered and authorised to act for
 * the organisation. That is an attestation, not terms of use, and it is now
 * named `authorised` so it is not mistaken for one.
 */
class RegistrationFieldsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function applicantPayload(array $overrides = []): array
    {
        $this->sequence++;

        return array_merge([
            'full_name' => 'Tendai Moyo',
            'email' => "applicant{$this->sequence}@example.test",
            'phone' => '0771234567',
            'password' => 'ChangeMe123',
            'password_confirmation' => 'ChangeMe123',
        ], $overrides);
    }

    private function providerPayload(array $overrides = []): array
    {
        $this->sequence++;
        Storage::fake('local');

        return array_merge([
            'full_name' => 'Chikafu Education Trust',
            'contact_name' => 'Rumbi Chikafu',
            'contact_role' => 'Trustee',
            'email' => "trust{$this->sequence}@example.test",
            'organisation_type' => ProviderOrgType::ALL[0],
            'registration_country' => 'ZW',
            'registration_number' => 'MA 1234/2015',
            'certificate' => UploadedFile::fake()->create('registration.pdf', 40, 'application/pdf'),
            'password' => 'ChangeMe123',
            'password_confirmation' => 'ChangeMe123',
            'authorised' => '1',
        ], $overrides);
    }

    // ------------------------------------------------------ applicant ---------

    public function test_the_applicant_form_has_no_terms_checkbox(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="terms"', $html);
        $this->assertStringNotContainsStringIgnoringCase('terms of use', $html);
        $this->assertStringNotContainsStringIgnoringCase('privacy policy', $html);
        $this->assertStringContainsString('name="password"', $html);
    }

    public function test_an_applicant_can_register_without_agreeing_to_terms(): void
    {
        $this->post('/register', $this->applicantPayload(['email' => 'no-terms@example.test']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'no-terms@example.test']);
    }

    /** A client that still sends the old field is not refused, and nothing is stored from it. */
    public function test_a_stale_terms_field_is_simply_ignored(): void
    {
        $this->post('/register', $this->applicantPayload(['email' => 'stale-terms@example.test', 'terms' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'stale-terms@example.test']);
    }

    // ------------------------------------------------------- provider ---------

    public function test_the_provider_form_asks_for_a_registration_number_but_no_terms(): void
    {
        $html = $this->get('/register/provider')->assertOk()->getContent();

        $this->assertStringContainsString('name="registration_number"', $html);
        $this->assertStringContainsString('name="registration_number_note"', $html);
        $this->assertStringNotContainsString('name="terms"', $html);
        // The certificate upload and the authorisation confirmation remain.
        $this->assertStringContainsString('name="certificate"', $html);
        $this->assertStringContainsString('name="authorised"', $html);
    }

    public function test_a_provider_without_a_registration_number_can_register_by_explaining_why(): void
    {
        $this->post('/register/provider', $this->providerPayload([
            'email' => 'no-number@example.test',
            'registration_number' => '',
            'registration_number_note' => 'A public university established by an Act of Parliament; the Act is attached.',
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'no-number@example.test')->firstOrFail();
        $profile = ProviderProfile::where('user_id', $user->user_id)->firstOrFail();

        $this->assertNull($profile->registration_number);
        $this->assertStringContainsString('Act of Parliament', $profile->registration_number_note);
        $this->assertNotEmpty($profile->certificate_path, 'the certificate is still required and stored');
    }

    public function test_a_provider_with_neither_a_number_nor_an_explanation_is_refused(): void
    {
        $this->post('/register/provider', $this->providerPayload([
            'email' => 'neither@example.test',
            'registration_number' => '',
        ]))->assertSessionHasErrors(['registration_number', 'registration_number_note']);

        $this->assertDatabaseMissing('users', ['email' => 'neither@example.test']);
    }

    public function test_a_provider_must_still_confirm_they_are_authorised(): void
    {
        $payload = $this->providerPayload(['email' => 'unconfirmed@example.test']);
        unset($payload['authorised']);

        $this->post('/register/provider', $payload)->assertSessionHasErrors('authorised');

        $this->assertDatabaseMissing('users', ['email' => 'unconfirmed@example.test']);
    }

    public function test_a_provider_must_still_upload_a_certificate(): void
    {
        $payload = $this->providerPayload(['email' => 'no-cert@example.test']);
        unset($payload['certificate']);

        $this->post('/register/provider', $payload)->assertSessionHasErrors('certificate');
    }

    public function test_a_registration_number_in_any_format_is_stored(): void
    {
        foreach (['PVO 99/2026', '2019/123456/08', 'CH-020.3.912.345-6', '12345678', 'NPO 123-456'] as $i => $number) {
            $this->post('/register/provider', $this->providerPayload([
                'email' => "number{$i}@example.test",
                'registration_number' => $number,
            ]))->assertSessionHasNoErrors();

            $user = User::where('email', "number{$i}@example.test")->firstOrFail();
            $this->assertDatabaseHas('provider_profiles', ['user_id' => $user->user_id, 'registration_number' => $number]);
        }
    }

    public function test_a_registration_number_with_markup_or_symbols_is_refused(): void
    {
        $this->post('/register/provider', $this->providerPayload([
            'email' => 'markup@example.test',
            'registration_number' => '<script>1</script>',
        ]))->assertSessionHasErrors('registration_number');
    }

    public function test_the_admin_review_list_shows_the_registration_number(): void
    {
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $html = $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->getContent();

        $pending = ProviderProfile::whereHas('user', fn ($q) => $q->where('email', 'trust@scholarzim.co.zw'))->firstOrFail();
        $this->assertStringContainsString(e($pending->user->displayName()), $html);
        $this->assertStringContainsString('Reg. no. ' . e($pending->registration_number), $html);
    }

    // ------------------------------------------------------ database ----------

    /** Existing numbers are kept on record, and an organisation without one may leave it blank. */
    public function test_the_registration_number_column_is_nullable_not_dropped(): void
    {
        $column = collect(Schema::getColumns('provider_profiles'))->firstWhere('name', 'registration_number');

        $this->assertNotNull($column, 'the column is kept so existing data is not destroyed');
        $this->assertTrue($column['nullable'], 'the column must accept a profile registered without a number');
    }
}
