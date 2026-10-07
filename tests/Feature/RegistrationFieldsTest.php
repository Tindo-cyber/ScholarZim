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
 * Retired: the provider "registration number" (the certificate is what an
 * administrator actually verifies) and the applicant "terms of use and privacy
 * policy" checkbox (there is no terms or privacy page for it to point at).
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
            'email' => "trust{$this->sequence}@example.test",
            'organisation_type' => ProviderOrgType::ALL[0],
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

    public function test_the_provider_form_has_no_registration_number_or_terms(): void
    {
        $html = $this->get('/register/provider')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="registration_number"', $html);
        $this->assertStringNotContainsStringIgnoringCase('Registration number', $html);
        $this->assertStringNotContainsString('name="terms"', $html);
        // The certificate upload and the authorisation confirmation remain.
        $this->assertStringContainsString('name="certificate"', $html);
        $this->assertStringContainsString('name="authorised"', $html);
    }

    public function test_a_provider_can_register_without_a_registration_number(): void
    {
        $this->post('/register/provider', $this->providerPayload(['email' => 'no-number@example.test']))
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'no-number@example.test')->firstOrFail();
        $profile = ProviderProfile::where('user_id', $user->user_id)->firstOrFail();

        $this->assertNull($profile->getAttributes()['registration_number'] ?? null);
        $this->assertNotEmpty($profile->certificate_path, 'the certificate is still required and stored');
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

    /** A client still sending the old number is not refused, and it is not stored. */
    public function test_a_stale_registration_number_is_ignored_and_not_stored(): void
    {
        $this->post('/register/provider', $this->providerPayload([
            'email' => 'stale-number@example.test',
            'registration_number' => 'PVO 99/2026',
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'stale-number@example.test')->firstOrFail();

        $this->assertDatabaseMissing('provider_profiles', ['user_id' => $user->user_id, 'registration_number' => 'PVO 99/2026']);
    }

    public function test_the_admin_review_list_no_longer_shows_a_registration_number(): void
    {
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $html = $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->getContent();

        // The seeded pending provider's row is on the page, so the absence below means something.
        $pending = ProviderProfile::whereHas('user', fn ($q) => $q->where('email', 'trust@scholarzim.co.zw'))->firstOrFail();
        $this->assertStringContainsString(e($pending->user->displayName()), $html);

        $this->assertDoesNotMatchRegularExpression('/>\s*Reg\.\s/', $html);
    }

    // ------------------------------------------------------ database ----------

    /** Existing numbers are kept on record, but a new provider needs none. */
    public function test_the_registration_number_column_is_nullable_not_dropped(): void
    {
        $column = collect(Schema::getColumns('provider_profiles'))->firstWhere('name', 'registration_number');

        $this->assertNotNull($column, 'the column is kept so existing data is not destroyed');
        $this->assertTrue($column['nullable'], 'the column must accept a profile registered without a number');
    }

    public function test_nothing_in_the_application_code_reads_the_registration_number(): void
    {
        $offenders = [];

        foreach ([app_path(), resource_path('views'), base_path('routes')] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (preg_match('/\.(php|js)$/', $file->getFilename())
                    && str_contains(file_get_contents($file->getPathname()), 'registration_number')) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'registration_number must not be read or written by the application');
    }
}
