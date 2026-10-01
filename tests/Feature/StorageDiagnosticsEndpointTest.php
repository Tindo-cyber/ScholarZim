<?php

namespace Tests\Feature;

use App\Models\ProviderProfile;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\ProviderOrgType;
use App\Support\RoleNames;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The browser-reachable diagnostic for one provider's certificate_path and
 * whether the configured disk actually has it.
 *
 * Exists for the same reason as /admin/mail-diagnostics: this Render plan has
 * no Shell tab, so a question as simple as "what does this provider's
 * certificate_path look like, and does exists() throw or just say no" has no
 * way to be answered without a route that answers it.
 */
class StorageDiagnosticsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'test-access-key-id-must-never-be-printed';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.key' => self::FAKE_KEY,
            'filesystems.disks.s3.secret' => 'test-secret-must-never-be-printed-9f8e7d6c',
            'filesystems.disks.s3.bucket' => 'scholarzim-test-bucket',
            'filesystems.disks.s3.region' => 'auto',
            'filesystems.disks.s3.endpoint' => 'https://example.r2.cloudflarestorage.com',
        ]);
    }

    // ------------------------------------------------------------- access --

    public function test_it_is_closed_to_anonymous_visitors(): void
    {
        $this->get('/admin/providers/' . $this->providerWithCertificate()->user_id . '/certificate/diagnose')
            ->assertRedirect('/login');
    }

    public function test_it_is_closed_to_a_non_administrator(): void
    {
        $this->actingAs($this->student())
            ->get('/admin/providers/' . $this->providerWithCertificate()->user_id . '/certificate/diagnose')
            ->assertForbidden();
    }

    // -------------------------------------------------------- credentials --

    public function test_the_response_never_contains_the_secret_or_key(): void
    {
        $profile = $this->providerWithCertificate();

        $body = (string) $this->actingAs($this->admin())
            ->get('/admin/providers/' . $profile->user_id . '/certificate/diagnose')
            ->getContent();

        $this->assertStringNotContainsString(self::FAKE_KEY, $body);
        $this->assertStringNotContainsString('test-secret-must-never-be-printed-9f8e7d6c', $body);
    }

    public function test_credentials_are_reported_as_fingerprints(): void
    {
        $profile = $this->providerWithCertificate();

        $this->actingAs($this->admin())
            ->get('/admin/providers/' . $profile->user_id . '/certificate/diagnose')
            ->assertOk()
            ->assertJsonPath('stage_1_configuration.key.configured', true)
            ->assertJsonPath('stage_1_configuration.key.length', strlen(self::FAKE_KEY))
            ->assertJsonPath(
                'stage_1_configuration.key.sha256_prefix',
                substr(hash('sha256', self::FAKE_KEY), 0, 12)
            )
            ->assertJsonPath('stage_1_configuration.bucket', 'scholarzim-test-bucket');
    }

    // ------------------------------------------------------------ outcomes --

    public function test_a_provider_with_no_profile_is_reported_as_not_found(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/providers/999999/certificate/diagnose')
            ->assertStatus(404)
            ->assertJsonPath('stage_2_provider_profile.found', false);
    }

    /** The exact symptom reported: certificate_filename recorded, certificate_path empty. */
    public function test_a_blank_certificate_path_is_reported_without_touching_the_disk(): void
    {
        $profile = ProviderProfile::create([
            'user_id' => $this->providerUser()->user_id,
            'organisation_type' => ProviderOrgType::PRIVATE_COMPANY,
            'registration_number' => 'REG-BLANK',
            'certificate_path' => '',
            'certificate_filename' => 'registration.pdf',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/providers/' . $profile->user_id . '/certificate/diagnose')
            ->assertOk()
            ->assertJsonPath('stage_2_provider_profile.certificate_path_blank', true)
            ->assertJsonPath('stage_2_provider_profile.certificate_filename', 'registration.pdf')
            ->assertJsonPath('stage_3_existence_check.attempted', false);
    }

    public function test_a_file_that_genuinely_exists_is_reported_as_such(): void
    {
        Storage::fake('s3');
        $profile = $this->providerWithCertificate();
        Storage::disk('s3')->put($profile->certificate_path, 'pdf bytes');

        $this->actingAs($this->admin())
            ->get('/admin/providers/' . $profile->user_id . '/certificate/diagnose')
            ->assertOk()
            ->assertJsonPath('stage_3_existence_check.attempted', true)
            ->assertJsonPath('stage_3_existence_check.exists', true);
    }

    public function test_a_file_that_is_genuinely_missing_is_reported_as_such(): void
    {
        Storage::fake('s3');
        $profile = $this->providerWithCertificate();

        $this->actingAs($this->admin())
            ->get('/admin/providers/' . $profile->user_id . '/certificate/diagnose')
            ->assertOk()
            ->assertJsonPath('stage_3_existence_check.attempted', true)
            ->assertJsonPath('stage_3_existence_check.exists', false)
            ->assertJsonPath('stage_3_existence_check.exception_chain', null);
    }

    /** The one case this endpoint exists to surface: a disk-level failure, with its full cause. */
    public function test_a_disk_error_is_reported_with_its_full_exception_chain(): void
    {
        $profile = $this->providerWithCertificate();

        $failingDisk = Mockery::mock(FilesystemContract::class);
        $failingDisk->shouldReceive('exists')->once()->andThrow(
            \League\Flysystem\UnableToCheckFileExistence::forLocation(
                $profile->certificate_path,
                new RuntimeException('AWS HTTP error: Client error: `HEAD ...` resulted in a `403 Forbidden` response: AccessDenied')
            )
        );
        Storage::shouldReceive('disk')->with('s3')->andReturn($failingDisk);

        $this->actingAs($this->admin())
            ->get('/admin/providers/' . $profile->user_id . '/certificate/diagnose')
            ->assertOk()
            ->assertJsonPath('stage_3_existence_check.attempted', true)
            ->assertJsonPath('stage_3_existence_check.exists', null)
            ->assertJsonCount(2, 'stage_3_existence_check.exception_chain')
            ->assertJsonPath('stage_3_existence_check.exception_chain.0', fn ($v) => str_contains($v, 'UnableToCheckFileExistence'))
            ->assertJsonPath('stage_3_existence_check.exception_chain.1', fn ($v) => str_contains($v, 'AccessDenied'));
    }

    // ------------------------------------------------------------ write test --

    public function test_the_write_test_is_closed_to_a_non_administrator(): void
    {
        $this->actingAs($this->student())
            ->get('/admin/storage-diagnostics/write-test')
            ->assertForbidden();
    }

    public function test_a_successful_round_trip_is_reported_and_cleans_up_after_itself(): void
    {
        Storage::fake('s3');

        $response = $this->actingAs($this->admin())
            ->get('/admin/storage-diagnostics/write-test')
            ->assertOk()
            ->assertJsonPath('stage', 'complete')
            ->assertJsonPath('succeeded', true)
            ->assertJsonPath('exists_after_write', true);

        $path = $response->json('path');
        $this->assertStringStartsWith('diagnostics/', $path);
        Storage::disk('s3')->assertMissing($path);
    }

    /**
     * The raw Flysystem driver's write() has a void return type and always
     * throws on failure - unlike Illuminate\Filesystem\FilesystemAdapter's
     * own put(), which can return false without throwing. Going through
     * getDriver() (see the controller) means there is no "returned false"
     * case left to report for this stage; only a thrown exception.
     */
    public function test_a_write_that_throws_is_reported_with_its_full_exception_chain(): void
    {
        $failingDriver = Mockery::mock(\League\Flysystem\FilesystemOperator::class);
        $failingDriver->shouldReceive('write')->once()->andThrow(
            \League\Flysystem\UnableToWriteFile::atLocation(
                'diagnostics/irrelevant.txt',
                'disk failure',
                new RuntimeException('AWS HTTP error: Client error: `PUT ...` resulted in a `403 Forbidden` response: InvalidAccessKeyId')
            )
        );
        $failingDisk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $failingDisk->shouldReceive('getDriver')->once()->andReturn($failingDriver);
        Storage::shouldReceive('disk')->with('s3')->andReturn($failingDisk);

        $this->actingAs($this->admin())
            ->get('/admin/storage-diagnostics/write-test')
            ->assertStatus(503)
            ->assertJsonPath('stage', 'write')
            ->assertJsonPath('succeeded', false)
            ->assertJsonCount(2, 'exception_chain')
            ->assertJsonPath('exception_chain.1', fn ($v) => str_contains($v, 'InvalidAccessKeyId'));
    }

    // ------------------------------------------------------------- helpers --

    private function providerWithCertificate(): ProviderProfile
    {
        return ProviderProfile::create([
            'user_id' => $this->providerUser()->user_id,
            'organisation_type' => ProviderOrgType::PRIVATE_COMPANY,
            'registration_number' => 'REG-' . uniqid(),
            'certificate_path' => 'provider-certificates/' . uniqid() . '.pdf',
            'certificate_filename' => 'registration.pdf',
            'submitted_at' => now(),
        ]);
    }

    private function providerUser(): User
    {
        return User::create([
            'role_id' => User::where('email', 'provider@scholarzim.co.zw')->firstOrFail()->role_id,
            'full_name' => 'Diagnostics Test Provider',
            'email' => 'diagnostics-provider-' . uniqid() . '@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => AccountStatus::PENDING,
            'email_verified' => true,
        ]);
    }

    private function admin(): User
    {
        return User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->firstOrFail();
    }

    private function student(): User
    {
        return User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }
}
