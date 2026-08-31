<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\ProviderOrgType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Objective 4 and 5: a provider is verified by an administrator before they can
 * publish anything.
 *
 *     register -> PENDING -> admin reviews the certificate -> ACTIVE -> publish
 *
 * The gate that matters is `account.active` on the publishing routes: a provider
 * awaiting verification can sign in and see their dashboard, but must not be
 * able to post a scholarship. That was covered only indirectly before this.
 */
class ProviderVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    public function test_a_new_provider_starts_pending_verification(): void
    {
        $provider = $this->register();

        $this->assertSame(AccountStatus::PENDING, $provider->account_status);
        $this->assertNotNull($provider->providerProfile, 'the certificate and org details are kept');
        $this->assertNull($provider->providerProfile->reviewed_at);
    }

    /** Administrators are told there is something in the queue. */
    public function test_registration_notifies_the_administrators(): void
    {
        $this->register();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->user_id,
            'type' => NotificationType::PROVIDER_APPLICATION,
        ]);
    }

    public function test_an_unverified_provider_cannot_publish(): void
    {
        $provider = $this->register();

        $this->actingAs($provider)->get('/provider/dashboard')->assertOk();

        // The gate: publishing routes carry account.active, the dashboard does not.
        $this->actingAs($provider)->get('/opportunities/create')->assertRedirect();
    }

    public function test_an_administrator_verifies_a_provider_who_can_then_publish(): void
    {
        $provider = $this->register();

        $this->actingAs($this->admin)
            ->post('/admin/users/providers/' . $provider->user_id . '/approve')
            ->assertRedirect();

        $provider->refresh();

        $this->assertSame(AccountStatus::ACTIVE, $provider->account_status);
        $this->assertNotNull($provider->providerProfile->reviewed_at);
        $this->assertSame($this->admin->email, $provider->providerProfile->reviewed_by);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $provider->user_id,
            'type' => NotificationType::PROVIDER_APPROVED,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => AuditAction::APPROVE_PROVIDER,
            'entity_id' => $provider->user_id,
        ]);

        // AuthenticateSession binds a session to the account that opened it, so
        // switching actors needs a clean one.
        $this->flushSession();
        $this->actingAs($provider)->get('/opportunities/create')->assertOk();
    }

    public function test_a_rejected_provider_is_told_why_and_still_cannot_publish(): void
    {
        $provider = $this->register();

        $this->actingAs($this->admin)
            ->post('/admin/users/providers/' . $provider->user_id . '/reject', [
                'reason' => 'The registration certificate is unreadable.',
            ])
            ->assertRedirect();

        $provider->refresh();

        $this->assertSame(AccountStatus::REJECTED, $provider->account_status);
        $this->assertSame(
            'The registration certificate is unreadable.',
            $provider->providerProfile->rejection_reason
        );

        $this->assertSame(
            1,
            Notification::where('user_id', $provider->user_id)
                ->where('type', NotificationType::PROVIDER_REJECTED)
                ->count()
        );

        $this->flushSession();
        $this->actingAs($provider)->get('/opportunities/create')->assertRedirect();
    }

    /** Verification is an administrative control, not something a provider does. */
    public function test_a_provider_cannot_verify_themselves(): void
    {
        $provider = $this->register();

        $this->actingAs($provider)
            ->post('/admin/users/providers/' . $provider->user_id . '/approve')
            ->assertForbidden();

        $this->assertSame(AccountStatus::PENDING, $provider->fresh()->account_status);
    }

    // ------------------------------------------------------------- helpers --

    private function register(): User
    {
        Storage::fake('local');

        $this->post('/register/provider', [
            'full_name' => 'Chikafu Education Trust',
            'email' => 'trust@example.test',
            'phone' => '+263771234567',
            'organisation_type' => ProviderOrgType::ALL[0],
            'registration_number' => 'PVO/2024/001',
            'certificate' => UploadedFile::fake()->create('registration.pdf', 40, 'application/pdf'),
            'password' => 'ChangeMe123',
            'password_confirmation' => 'ChangeMe123',
            'terms' => '1',
        ])->assertRedirect();

        $this->flushSession();

        return User::with('providerProfile')->where('email', 'trust@example.test')->firstOrFail();
    }
}
