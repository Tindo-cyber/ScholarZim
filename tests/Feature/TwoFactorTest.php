<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\AuditAction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The second factor: setting it up, being challenged for it at sign-in, and the
 * recovery path when the phone is gone.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    /** Generating a secret is not the same as switching two-factor on. */
    public function test_setup_is_a_handshake_not_a_switch(): void
    {
        $this->actingAs($this->admin)
            ->post('/account/two-factor', ['current_password' => 'ChangeMe123'])
            ->assertRedirect()
            ->assertSessionHas('twoFactorSetup');

        $this->admin->refresh();

        $this->assertNotNull($this->admin->two_factor_secret);
        $this->assertFalse($this->admin->hasTwoFactorEnabled());
    }

    public function test_setup_requires_the_current_password(): void
    {
        $this->actingAs($this->admin)
            ->post('/account/two-factor', ['current_password' => 'not-the-password'])
            ->assertSessionHasErrors('current_password');

        $this->assertNull($this->admin->fresh()->two_factor_secret);
    }

    public function test_a_valid_code_confirms_setup_and_a_wrong_one_does_not(): void
    {
        $service = app(TwoFactorService::class);
        $setup = $service->generate($this->admin);

        $this->actingAs($this->admin)
            ->post('/account/two-factor/confirm', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($this->admin->fresh()->hasTwoFactorEnabled());

        $this->actingAs($this->admin)
            ->post('/account/two-factor/confirm', ['code' => $this->codeFor($setup['secret'])])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->admin->fresh()->hasTwoFactorEnabled());

        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::TWO_FACTOR_ENABLED]);
    }

    /** A stolen password alone must never produce a signed-in session. */
    public function test_signing_in_with_two_factor_on_stops_at_the_challenge(): void
    {
        $secret = $this->enableTwoFactor();

        $this->post('/login', [
            'email' => $this->admin->email,
            'password' => 'ChangeMe123',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();

        $this->post('/two-factor', ['code' => $this->codeFor($secret)])
            ->assertRedirect();

        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_a_wrong_challenge_code_leaves_the_visitor_signed_out(): void
    {
        $this->enableTwoFactor();

        $this->post('/login', [
            'email' => $this->admin->email,
            'password' => 'ChangeMe123',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->post('/two-factor', ['code' => '111111'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::TWO_FACTOR_CHALLENGE_FAILED]);
    }

    public function test_a_recovery_code_works_once_and_is_then_spent(): void
    {
        $this->enableTwoFactor();
        $this->admin->refresh();

        $codes = $this->admin->two_factor_recovery_codes;
        $this->assertNotEmpty($codes);

        $service = app(TwoFactorService::class);

        $this->assertTrue($service->challenge($this->admin, $codes[0]));
        $this->assertFalse($service->challenge($this->admin->fresh(), $codes[0]));
        $this->assertCount(count($codes) - 1, $this->admin->fresh()->two_factor_recovery_codes);
    }

    public function test_an_account_without_two_factor_signs_in_directly(): void
    {
        $this->post('/login', [
            'email' => $this->admin->email,
            'password' => 'ChangeMe123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_disabling_clears_the_secret_and_the_recovery_codes(): void
    {
        $this->enableTwoFactor();

        $this->actingAs($this->admin)
            ->delete('/account/two-factor', ['current_password' => 'ChangeMe123'])
            ->assertRedirect();

        $fresh = $this->admin->fresh();

        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertFalse($fresh->hasTwoFactorEnabled());
    }

    /** @return string the confirmed secret */
    private function enableTwoFactor(): string
    {
        $service = app(TwoFactorService::class);
        $setup = $service->generate($this->admin);
        $service->confirm($this->admin, $this->codeFor($setup['secret']));

        Auth::logout();
        $this->flushSession();

        return $setup['secret'];
    }

    /**
     * Generates the code an authenticator app would be showing right now, using
     * the same RFC 6238 construction the service verifies against.
     */
    private function codeFor(string $secret): string
    {
        $service = app(TwoFactorService::class);

        $method = new \ReflectionMethod($service, 'codeAt');
        $method->setAccessible(true);

        return $method->invoke($service, $secret, (int) floor(time() / 30));
    }
}
