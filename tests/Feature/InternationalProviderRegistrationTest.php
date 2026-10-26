<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\ProviderOrgType;
use App\Support\ProviderVerificationStatus;
use App\Support\RoleNames;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Organisations registered outside Zimbabwe register, are verified and are
 * refused through exactly the same workflow as Zimbabwean ones.
 *
 * The countries used here are representative, not a list of what is supported:
 * any ISO 3166-1 country is (App\Support\Countries).
 */
class InternationalProviderRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
        Storage::fake('local');
    }

    // --------------------------------------------------------- registration --

    public static function organisations(): array
    {
        return [
            'Zimbabwean trust' => ['ZW', 'Chikafu Education Trust', ProviderOrgType::FOUNDATION, '0242700000', 'MA 1234/2015'],
            'UK university' => ['GB', 'University of Northumbria Scholarships Office', ProviderOrgType::UNIVERSITY, '+44 20 7946 0000', 'RC000123'],
            'South African foundation' => ['ZA', 'Ubuntu Futures Foundation', ProviderOrgType::FOUNDATION, '+27 11 555 0100', '2019/123456/08'],
            'Kenyan NGO' => ['KE', 'Elimu Bora Initiative', ProviderOrgType::NGO, '+254 20 2222222', 'OP.218/051/19-456/11'],
            'German government agency' => ['DE', 'Bundesamt Stipendienwerk', ProviderOrgType::GOVERNMENT, '+49 30 1234567', 'HRB 98765 B'],
            'Canadian education organisation' => ['CA', 'Maple Learning Society', ProviderOrgType::OTHER, '+1 613 555 0199', '123456789RR0001'],
        ];
    }

    #[DataProvider('organisations')]
    public function test_an_organisation_registered_in_any_country_can_register(
        string $country, string $name, string $type, string $phone, string $number
    ): void {
        $this->post('/register/provider', $this->payload([
            'full_name' => $name,
            'registration_country' => $country,
            'organisation_type' => $type,
            'phone' => $phone,
            'registration_number' => $number,
        ]))->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $user = User::with('providerProfile')->where('full_name', $name)->firstOrFail();

        $this->assertSame(RoleNames::PROVIDER, $user->roleName());
        $this->assertSame(AccountStatus::PENDING, $user->account_status, 'no country skips verification');
        $this->assertSame($phone, $user->phone);
        $this->assertSame($country, $user->providerProfile->registration_country);
        $this->assertSame($number, $user->providerProfile->registration_number);
        $this->assertSame($type, $user->providerProfile->organisation_type);
        $this->assertSame('Ama Mensah', $user->providerProfile->contact_name);
        $this->assertSame('Scholarships Officer', $user->providerProfile->contact_role);
        $this->assertNull($user->providerProfile->reviewed_at);
        $this->assertSame(ProviderVerificationStatus::PENDING, $user->providerProfile->verificationStatus());
    }

    public function test_the_country_code_is_accepted_in_either_case_and_stored_upper_case(): void
    {
        $this->post('/register/provider', $this->payload(['registration_country' => 'gb', 'email' => 'lower@example.test']))
            ->assertSessionHasNoErrors();

        $this->assertSame('GB', $this->profileFor('lower@example.test')->registration_country);
    }

    public function test_the_form_offers_countries_worldwide_with_none_chosen_in_advance(): void
    {
        $html = $this->get('/register/provider')->assertOk()->getContent();

        preg_match('#<select[^>]*name="registration_country".*?</select>#s', $html, $m);
        $this->assertNotEmpty($m, 'the country selector renders');
        $select = $m[0];

        foreach (['ZW' => 'Zimbabwe', 'GB' => 'United Kingdom', 'ZA' => 'South Africa', 'NG' => 'Nigeria', 'BR' => 'Brazil', 'JP' => 'Japan', 'NZ' => 'New Zealand'] as $code => $name) {
            $this->assertStringContainsString('<option value="' . $code . '"', $select);
            $this->assertStringContainsString($name, $select);
        }

        $this->assertGreaterThan(240, substr_count($select, '<option value="'), 'a worldwide list, not a regional one');
        $this->assertStringNotContainsString('selected', $select, 'the organisation chooses; nothing is pre-selected, not even Zimbabwe');
        $this->assertStringContainsString('data-searchable-select', $html);
        $this->assertStringContainsString('not where your scholarships are held', $html);
    }

    public function test_a_missing_country_is_refused(): void
    {
        $payload = $this->payload(['email' => 'no-country@example.test']);
        unset($payload['registration_country']);

        $this->post('/register/provider', $payload)->assertSessionHasErrors('registration_country');
        $this->assertDatabaseMissing('users', ['email' => 'no-country@example.test']);
    }

    public static function invalidCountries(): array
    {
        return [
            'a name, not a code' => ['United Kingdom'],
            'an unassigned code' => ['XX'],
            'a three-letter code' => ['GBR'],
            'markup' => ['<b>ZW</b>'],
            'a study-destination option' => ['Any country'],
        ];
    }

    #[DataProvider('invalidCountries')]
    public function test_a_country_not_in_the_list_is_refused(string $country): void
    {
        $this->post('/register/provider', $this->payload(['registration_country' => $country, 'email' => 'bad-country@example.test']))
            ->assertSessionHasErrors('registration_country');

        $this->assertDatabaseMissing('users', ['email' => 'bad-country@example.test']);
    }

    public static function requiredFields(): array
    {
        return [
            'organisation name' => ['full_name'],
            'contact person' => ['contact_name'],
            'contact position' => ['contact_role'],
            'organisation type' => ['organisation_type'],
            'registration document' => ['certificate'],
            'authorisation' => ['authorised'],
            'email' => ['email'],
        ];
    }

    #[DataProvider('requiredFields')]
    public function test_missing_registration_information_is_refused(string $field): void
    {
        $payload = $this->payload(['email' => 'incomplete@example.test']);
        unset($payload[$field]);

        $this->post('/register/provider', $payload)->assertSessionHasErrors($field);
        $this->assertSame(0, User::where('full_name', $payload['full_name'] ?? '')->where('email', 'incomplete@example.test')->count());
    }

    public static function invalidDocuments(): array
    {
        return [
            'an executable' => [fn () => UploadedFile::fake()->create('certificate.exe', 40, 'application/x-msdownload')],
            'a script' => [fn () => UploadedFile::fake()->create('certificate.php', 4, 'application/x-php')],
            'over 5 MB' => [fn () => UploadedFile::fake()->create('certificate.pdf', 6000, 'application/pdf')],
        ];
    }

    #[DataProvider('invalidDocuments')]
    public function test_an_invalid_registration_document_is_refused(\Closure $file): void
    {
        $this->post('/register/provider', $this->payload([
            'email' => 'bad-document@example.test',
            'registration_country' => 'GB',
            'certificate' => $file(),
        ]))->assertSessionHasErrors('certificate');

        $this->assertDatabaseMissing('users', ['email' => 'bad-document@example.test']);
    }

    public function test_an_international_phone_number_must_still_be_a_real_one(): void
    {
        $this->post('/register/provider', $this->payload(['email' => 'bad-phone@example.test', 'registration_country' => 'GB', 'phone' => '+44 12']))
            ->assertSessionHasErrors('phone');

        $this->post('/register/provider', $this->payload(['email' => 'good-phone@example.test', 'registration_country' => 'GB', 'phone' => '+44 20 7946 0000']))
            ->assertSessionHasNoErrors();
    }

    public function test_registration_is_audited_and_announced_with_the_country(): void
    {
        $this->post('/register/provider', $this->payload(['email' => 'audited@example.test', 'registration_country' => 'ZA']))
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'audited@example.test')->firstOrFail();

        $this->assertTrue(AuditLog::where('action', AuditAction::REGISTER)->where('entity_id', $user->user_id)
            ->where('details', 'like', '%registered in South Africa%')->exists());

        $notice = Notification::where('user_id', $this->admin->user_id)
            ->where('type', NotificationType::PROVIDER_APPLICATION)
            ->where('related_id', $user->user_id)
            ->firstOrFail();
        $this->assertStringContainsString('South Africa', $notice->message);
    }

    // ------------------------------------------------------- verification --

    public function test_a_foreign_provider_joins_the_same_verification_queue(): void
    {
        $provider = $this->register('GB', 'queue@example.test');

        $html = $this->as($this->admin)->get('/admin/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString(e($provider->displayName()), $html);
        $this->assertStringContainsString('United Kingdom', $html);
        $this->assertStringContainsString(route('admin.providers.show', $provider->user_id), $html);
        $this->assertStringContainsString('Reg. no. RC000123', $html);
    }

    public function test_an_administrator_sees_the_full_verification_record(): void
    {
        $provider = $this->register('GB', 'record@example.test', ['phone' => '+44 20 7946 0000']);

        $this->as($this->admin)->get(route('admin.providers.show', $provider->user_id))
            ->assertOk()
            ->assertSee('United Kingdom')
            ->assertSee('(GB)')
            ->assertSee('RC000123')
            ->assertSee('University / College')
            ->assertSee('Ama Mensah')
            ->assertSee('Scholarships Officer')
            ->assertSee('record@example.test')
            ->assertSee('+44 20 7946 0000')
            ->assertSee('registration.pdf')
            ->assertSee(route('admin.providers.certificate', $provider->user_id))
            ->assertSee('Awaiting review')
            ->assertSee('Request more information');
    }

    public function test_an_administrator_can_open_a_foreign_providers_document_and_it_is_audited(): void
    {
        $provider = $this->register('ZA', 'document@example.test');

        $this->as($this->admin)->get(route('admin.providers.certificate', $provider->user_id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertTrue(AuditLog::where('action', AuditAction::VIEW_PROVIDER_CERTIFICATE)
            ->where('entity_id', $provider->providerProfile->profile_id)->exists());
    }

    public function test_no_one_else_can_open_the_document_or_the_review_page(): void
    {
        $provider = $this->register('ZA', 'private@example.test');
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $otherProvider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        foreach ([$student, $otherProvider, $provider] as $user) {
            $this->as($user)->get(route('admin.providers.certificate', $provider->user_id))->assertForbidden();
            $this->as($user)->get(route('admin.providers.show', $provider->user_id))->assertForbidden();
        }

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get(route('admin.providers.certificate', $provider->user_id))->assertRedirect(route('login'));
    }

    public function test_approving_a_foreign_provider_lets_it_publish_like_any_other(): void
    {
        $provider = $this->register('GB', 'approve@example.test');

        $this->as($provider)->get('/opportunities/create')->assertRedirect();

        $this->as($this->admin)->post(route('admin.providers.approve', $provider->user_id))
            ->assertSessionHas('successMessage');

        $provider->refresh();
        $this->assertSame(AccountStatus::ACTIVE, $provider->account_status);
        $this->assertSame(ProviderVerificationStatus::APPROVED, $provider->providerProfile->verificationStatus());
        $this->assertSame('GB', $provider->providerProfile->registration_country, 'approval does not touch the country');

        $this->assertTrue(AuditLog::where('action', AuditAction::APPROVE_PROVIDER)->where('entity_id', $provider->user_id)
            ->where('details', 'like', '%registered in United Kingdom%')->exists());
        $this->assertDatabaseHas('notifications', ['user_id' => $provider->user_id, 'type' => NotificationType::PROVIDER_APPROVED]);

        $this->as($provider)->get('/opportunities/create')->assertOk();
    }

    public function test_rejecting_a_foreign_provider_keeps_it_from_publishing(): void
    {
        $provider = $this->register('ZA', 'reject@example.test');

        $this->as($this->admin)->post(route('admin.providers.reject', $provider->user_id), [
            'reason' => 'The document names a different organisation.',
        ])->assertSessionHas('successMessage');

        $provider->refresh();
        $this->assertSame(AccountStatus::REJECTED, $provider->account_status);
        $this->assertSame(ProviderVerificationStatus::REJECTED, $provider->providerProfile->verificationStatus());
        $this->assertTrue(AuditLog::where('action', AuditAction::REJECT_PROVIDER)->where('entity_id', $provider->user_id)
            ->where('details', 'like', '%registered in South Africa%')->exists());

        $this->as($provider)->get('/opportunities/create')->assertRedirect();
        $this->as($provider)->post('/opportunities/create', ['title' => 'Should not exist'])->assertRedirect();
        $this->assertDatabaseMissing('opportunities', ['title' => 'Should not exist']);
    }

    // ------------------------------------------- asking for more information --

    public function test_an_administrator_can_ask_for_more_information_without_approving(): void
    {
        $provider = $this->register('NG', 'more-info@example.test');

        $this->as($this->admin)->post(route('admin.providers.requestInformation', $provider->user_id), [
            'note' => 'Please upload the CAC certificate with an English translation.',
        ])->assertSessionHas('successMessage');

        $profile = $provider->providerProfile->fresh();
        $this->assertSame(AccountStatus::PENDING, $provider->fresh()->account_status, 'asking is not approving');
        $this->assertNull($profile->reviewed_at);
        $this->assertSame(ProviderVerificationStatus::INFO_REQUESTED, $profile->verificationStatus());
        $this->assertDatabaseHas('notifications', ['user_id' => $provider->user_id, 'type' => NotificationType::PROVIDER_INFO_REQUESTED]);
        $this->assertTrue(AuditLog::where('action', AuditAction::REQUEST_PROVIDER_INFO)->where('entity_id', $provider->user_id)
            ->where('details', 'like', '%registered in Nigeria%')->exists());

        $this->as($provider)->get('/provider/dashboard')
            ->assertOk()
            ->assertSee('Please upload the CAC certificate with an English translation.')
            ->assertSee('Update and send for review');
        $this->as($provider)->get('/opportunities/create')->assertRedirect();

        // Still in the queue, labelled for what it is waiting on.
        $this->as($this->admin)->get('/admin/dashboard')->assertSee('More information requested');
    }

    public function test_the_organisation_answers_and_returns_to_the_queue(): void
    {
        $provider = $this->register('NG', 'answer@example.test');
        $oldPath = $provider->providerProfile->certificate_path;
        $this->as($this->admin)->post(route('admin.providers.requestInformation', $provider->user_id), ['note' => 'A clearer scan, please.']);

        $this->travel(1)->minutes();
        $this->as($provider)->post(route('provider.registration.update'), [
            'registration_country' => 'NG',
            'registration_number' => 'RC 1234567',
            'contact_name' => 'Chidi Okafor',
            'contact_role' => 'Executive Director',
            'certificate' => UploadedFile::fake()->create('cac-certificate.pdf', 60, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertSessionHas('successMessage');

        $profile = $provider->providerProfile->fresh();
        $this->assertSame('RC 1234567', $profile->registration_number);
        $this->assertSame('Chidi Okafor', $profile->contact_name);
        $this->assertSame('cac-certificate.pdf', $profile->certificate_filename);
        $this->assertNotSame($oldPath, $profile->certificate_path);
        $this->assertSame(ProviderVerificationStatus::PENDING, $profile->verificationStatus());
        $this->assertSame(AccountStatus::PENDING, $provider->fresh()->account_status, 'answering is not approval');
        $this->assertTrue(AuditLog::where('action', AuditAction::UPDATE_PROVIDER_REGISTRATION)->where('entity_id', $provider->user_id)->exists());

        $this->as($this->admin)->get(route('admin.providers.certificate', $provider->user_id))->assertOk();
    }

    public function test_the_organisations_update_is_validated_like_registration(): void
    {
        $provider = $this->register('NG', 'update-validation@example.test');

        $this->as($provider)->post(route('provider.registration.update'), [
            'registration_country' => 'Narnia',
            'registration_number' => '',
            'registration_number_note' => '',
            'contact_name' => 'Chidi Okafor',
            'contact_role' => 'Director',
            'certificate' => UploadedFile::fake()->create('cert.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrorsIn('registration', ['registration_country', 'registration_number', 'certificate']);

        $this->assertSame('NG', $provider->providerProfile->fresh()->registration_country);
    }

    public function test_a_verified_organisation_cannot_change_its_checked_details_itself(): void
    {
        $provider = $this->register('GB', 'locked@example.test');
        $this->as($this->admin)->post(route('admin.providers.approve', $provider->user_id));

        $this->as($provider)->post(route('provider.registration.update'), [
            'registration_country' => 'FR',
            'registration_number' => 'X1',
            'contact_name' => 'Someone Else',
            'contact_role' => 'Director',
        ])->assertSessionHas('errorMessage');

        $this->assertSame('GB', $provider->providerProfile->fresh()->registration_country);
    }

    public function test_requesting_information_is_only_for_undecided_organisations_and_only_by_administrators(): void
    {
        $provider = $this->register('GB', 'decided@example.test');

        $this->as($provider)->post(route('admin.providers.requestInformation', $provider->user_id), ['note' => 'x'])->assertForbidden();

        $this->as($this->admin)->post(route('admin.providers.approve', $provider->user_id));
        $this->as($this->admin)->post(route('admin.providers.requestInformation', $provider->user_id), ['note' => 'Too late'])
            ->assertSessionHas('errorMessage');

        $this->assertSame(AccountStatus::ACTIVE, $provider->fresh()->account_status);
    }

    // ------------------------------------------------- older provider accounts --

    public function test_an_older_account_with_no_country_is_shown_as_not_recorded_not_as_zimbabwe(): void
    {
        $legacy = $this->legacyProvider(AccountStatus::ACTIVE, reviewed: true);

        $this->assertNull($legacy->providerProfile->registration_country);
        $this->assertNull($legacy->providerProfile->registrationCountryName());

        $this->as($this->admin)->get(route('admin.providers.show', $legacy->user_id))
            ->assertOk()
            ->assertSee('Country of registration not recorded')
            ->assertDontSee('<option value="ZW" selected', false);

        $this->as($legacy)->get('/provider/dashboard')->assertOk()->assertSee('An administrator will record it');
    }

    public function test_an_older_verified_account_keeps_publishing_without_a_country(): void
    {
        $legacy = $this->legacyProvider(AccountStatus::ACTIVE, reviewed: true);

        $this->as($legacy)->get('/opportunities/create')->assertOk();
    }

    public function test_an_older_pending_account_cannot_be_approved_until_its_country_is_recorded(): void
    {
        $legacy = $this->legacyProvider(AccountStatus::PENDING, reviewed: false);

        $this->as($this->admin)->post(route('admin.providers.approve', $legacy->user_id))
            ->assertSessionHas('errorMessage');
        $this->assertSame(AccountStatus::PENDING, $legacy->fresh()->account_status);

        $this->as($this->admin)->post(route('admin.providers.registration', $legacy->user_id), [
            'registration_country' => 'BW',
            'registration_number' => 'CO2003/1234',
        ])->assertSessionHasNoErrors()->assertSessionHas('successMessage');

        $profile = $legacy->providerProfile->fresh();
        $this->assertSame('BW', $profile->registration_country);
        $this->assertSame(AccountStatus::PENDING, $legacy->fresh()->account_status, 'recording details is not approval');

        $entry = AuditLog::where('action', AuditAction::UPDATE_PROVIDER_REGISTRATION)->where('entity_id', $legacy->user_id)->firstOrFail();
        $this->assertStringContainsString('Botswana', $entry->details);

        $this->as($this->admin)->post(route('admin.providers.approve', $legacy->user_id))->assertSessionHas('successMessage');
        $this->assertSame(AccountStatus::ACTIVE, $legacy->fresh()->account_status);
    }

    public function test_an_administrator_records_a_country_without_disturbing_the_account(): void
    {
        $legacy = $this->legacyProvider(AccountStatus::ACTIVE, reviewed: true);
        $reviewedAt = $legacy->providerProfile->reviewed_at;

        $this->as($this->admin)->post(route('admin.providers.registration', $legacy->user_id), [
            'registration_country' => 'ZW',
        ])->assertSessionHasNoErrors();

        $legacy->refresh();
        $this->assertSame(AccountStatus::ACTIVE, $legacy->account_status);
        $this->assertSame('ZW', $legacy->providerProfile->registration_country);
        $this->assertEquals($reviewedAt, $legacy->providerProfile->reviewed_at);
        $this->assertSame('MA 99/2010', $legacy->providerProfile->registration_number, 'an old number on record is kept');
    }

    public function test_the_administrators_registration_form_validates_the_country(): void
    {
        $legacy = $this->legacyProvider(AccountStatus::ACTIVE, reviewed: true);

        $this->as($this->admin)->post(route('admin.providers.registration', $legacy->user_id), ['registration_country' => 'ZZ'])
            ->assertSessionHasErrorsIn('registration', 'registration_country');

        $this->as($legacy)->post(route('admin.providers.registration', $legacy->user_id), ['registration_country' => 'ZW'])
            ->assertForbidden();

        $this->assertNull($legacy->providerProfile->fresh()->registration_country);
    }

    // ------------------------------------------------------------ helpers --

    private function payload(array $overrides = []): array
    {
        $this->sequence++;

        return array_merge([
            'full_name' => 'International Scholars Fund ' . $this->sequence,
            'contact_name' => 'Ama Mensah',
            'contact_role' => 'Scholarships Officer',
            'email' => "org{$this->sequence}@example.test",
            'organisation_type' => ProviderOrgType::UNIVERSITY,
            'registration_country' => 'GB',
            'registration_number' => 'RC000123',
            'certificate' => UploadedFile::fake()->create('registration.pdf', 40, 'application/pdf'),
            'password' => 'ChangeMe123',
            'password_confirmation' => 'ChangeMe123',
            'authorised' => '1',
        ], $overrides);
    }

    private function register(string $country, string $email, array $overrides = []): User
    {
        $this->flushSession();
        $this->post('/register/provider', $this->payload(['registration_country' => $country, 'email' => $email] + $overrides))
            ->assertSessionHasNoErrors();

        return User::with('providerProfile')->where('email', $email)->firstOrFail();
    }

    private function profileFor(string $email): ProviderProfile
    {
        return ProviderProfile::where('user_id', User::where('email', $email)->value('user_id'))->firstOrFail();
    }

    private function as(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    /** A provider exactly as one registered before this change was stored: no country, contact or explanation. */
    private function legacyProvider(string $status, bool $reviewed): User
    {
        $user = User::create([
            'role_id' => Role::where('role_name', RoleNames::PROVIDER)->value('role_id'),
            'full_name' => 'Legacy Bursary Trust',
            'email' => 'legacy-' . strtolower($status) . '@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => $status,
            'email_verified' => true,
        ]);

        Storage::disk('local')->put('provider-certificates/legacy.pdf', '%PDF-1.4 legacy');

        ProviderProfile::create([
            'user_id' => $user->user_id,
            'organisation_type' => ProviderOrgType::FOUNDATION,
            'registration_number' => 'MA 99/2010',
            'certificate_path' => 'provider-certificates/legacy.pdf',
            'certificate_filename' => 'certificate.pdf',
            'submitted_at' => Carbon::now()->subYear(),
            'reviewed_at' => $reviewed ? Carbon::now()->subYear()->addDay() : null,
            'reviewed_by' => $reviewed ? 'admin@scholarzim.co.zw' : null,
        ]);

        return $user->load('providerProfile');
    }
}
