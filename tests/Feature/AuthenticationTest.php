<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AccountStatus;
use App\Support\AuditAction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Objective 5: plain Laravel session authentication.
 *
 *     email + password -> authenticate -> check account status
 *     -> regenerate the session -> land on the right dashboard
 *
 * There is no second factor and no token guard: one `web` guard over the
 * eloquent provider, with the password read out of the legacy `password_hash`
 * column by `User::getAuthPassword()`. These tests exercise the real HTTP
 * endpoint rather than `Auth::attempt` alone, because everything that has ever
 * gone wrong here went wrong between the two - a middleware, a redirect, or a
 * session that was never carried.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'ChangeMe123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // ------------------------------------------------------------- signing in --

    #[DataProvider('roleAccounts')]
    public function test_a_valid_account_signs_in_and_lands_on_its_dashboard(string $email, string $dashboard): void
    {
        $user = $this->userFor($email);

        $this->post('/login', ['email' => $email, 'password' => self::PASSWORD])
            ->assertRedirect($dashboard);

        $this->assertAuthenticatedAs($user);
    }

    public static function roleAccounts(): array
    {
        return [
            'student' => ['student@scholarzim.co.zw', '/applicant/dashboard'],
            'provider' => ['provider@scholarzim.co.zw', '/provider/dashboard'],
            'admin' => ['admin@scholarzim.co.zw', '/admin/dashboard'],
        ];
    }

    public function test_the_session_id_is_regenerated_on_sign_in(): void
    {
        $this->get('/login')->assertOk();
        $before = session()->getId();

        $this->post('/login', [
            'email' => 'student@scholarzim.co.zw',
            'password' => self::PASSWORD,
        ])->assertRedirect();

        // Session fixation: the id a visitor arrived with must not survive the
        // privilege change.
        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticated();
    }

    public function test_a_signed_in_user_can_sign_out(): void
    {
        $this->post('/login', ['email' => 'student@scholarzim.co.zw', 'password' => self::PASSWORD]);
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    // -------------------------------------------------------------- refusals --

    /** A request with no password field at all must fail validation, not fall through to Auth::attempt(). */
    public function test_a_missing_password_field_is_rejected(): void
    {
        $this->post('/login', ['email' => 'student@scholarzim.co.zw'])
            ->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_an_empty_password_is_rejected(): void
    {
        $this->post('/login', ['email' => 'student@scholarzim.co.zw', 'password' => ''])
            ->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    /**
     * The global TrimStrings middleware trims a whitespace-only value down to
     * an empty string before validation ever sees it, so this is refused by
     * the same "password is required" rule as a genuinely empty one - not by
     * a fuzzy hash mismatch.
     */
    public function test_a_whitespace_only_password_is_rejected(): void
    {
        $this->post('/login', ['email' => 'student@scholarzim.co.zw', 'password' => '   '])
            ->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_a_null_password_is_rejected(): void
    {
        $this->post('/login', ['email' => 'student@scholarzim.co.zw', 'password' => null])
            ->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->post('/login', [
            'email' => 'student@scholarzim.co.zw',
            'password' => 'NotThePassword1',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_unknown_email_is_rejected(): void
    {
        $this->post('/login', [
            'email' => 'nobody@example.test',
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** The refusal must not say which half was wrong. */
    public function test_both_refusals_read_identically(): void
    {
        $wrongPassword = $this->post('/login', [
            'email' => 'student@scholarzim.co.zw',
            'password' => 'NotThePassword1',
        ]);
        $this->flushSession();

        $unknownEmail = $this->post('/login', [
            'email' => 'nobody@example.test',
            'password' => self::PASSWORD,
        ]);

        $this->assertSame(
            $wrongPassword->getSession()->get('errors')->first('email'),
            $unknownEmail->getSession()->get('errors')->first('email')
        );
    }

    public function test_a_suspended_account_is_refused_and_holds_no_session(): void
    {
        $user = $this->userFor('student@scholarzim.co.zw');
        $user->update(['account_status' => AccountStatus::SUSPENDED]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Only SUSPENDED blocks sign-in. A provider awaiting verification must still
     * be able to get in and see their own dashboard - the publishing routes are
     * what `account.active` gates, not the login.
     */
    public function test_a_pending_provider_can_still_sign_in(): void
    {
        $user = $this->userFor('provider@scholarzim.co.zw');
        $user->update(['account_status' => AccountStatus::PENDING]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect('/provider/dashboard');

        $this->assertAuthenticatedAs($user->fresh());
    }

    /**
     * Verification is required to be *useful*, not to sign in. Nothing in the
     * app gates the session on it, so an unverified account must not be locked
     * out - it would have no way to reach the resend button.
     */
    public function test_an_unverified_account_can_still_sign_in(): void
    {
        $user = $this->userFor('student@scholarzim.co.zw');
        $user->update(['email_verified' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect('/applicant/dashboard');

        $this->assertAuthenticated();
    }

    public function test_a_failed_attempt_is_audited(): void
    {
        $this->post('/login', ['email' => 'student@scholarzim.co.zw', 'password' => 'wrong']);

        $this->assertDatabaseHas('audit_log', [
            'actor_email' => 'student@scholarzim.co.zw',
            'action' => AuditAction::LOGIN_FAILURE,
        ]);
    }

    public function test_repeated_failures_are_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'student@scholarzim.co.zw', 'password' => 'wrong' . $i]);
            $this->flushSession();
        }

        // The sixth is refused on rate, not on credentials - so a correct
        // password does not get a free pass through the limiter either.
        $this->post('/login', ['email' => 'student@scholarzim.co.zw', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ------------------------------------------------- how the password is held --

    public function test_passwords_are_stored_as_bcrypt_hashes_not_plaintext(): void
    {
        foreach (array_column(self::roleAccounts(), 0) as $email) {
            $user = $this->userFor($email);
            $hash = (string) $user->password_hash;

            $this->assertNotSame(self::PASSWORD, $hash, 'a password must never be stored in the clear');
            $this->assertSame('bcrypt', password_get_info($hash)['algoName'] ?? null);
            $this->assertTrue(Hash::check(self::PASSWORD, $hash));
        }
    }

    /**
     * The schema keeps the password in `password_hash`, not Laravel's default
     * `password`. `getAuthPassword()` is the single line that reconciles the two,
     * and every sign-in depends on it.
     */
    public function test_the_guard_reads_the_legacy_password_column(): void
    {
        $user = $this->userFor('student@scholarzim.co.zw');

        $this->assertSame($user->password_hash, $user->getAuthPassword());
        $this->assertTrue(Auth::attempt(['email' => $user->email, 'password' => self::PASSWORD]));

        Auth::logout();

        $this->assertFalse(Auth::attempt(['email' => $user->email, 'password' => 'wrong']));
    }

    /** One session guard, one provider, no token guard left behind. */
    public function test_the_auth_configuration_is_plain_session_authentication(): void
    {
        $this->assertSame('web', config('auth.defaults.guard'));
        $this->assertSame('session', config('auth.guards.web.driver'));
        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertSame(['web'], array_keys(config('auth.guards')));
    }

    // -------------------------------------------------- authentication state --

    /**
     * The sign-in page must present only the unauthenticated experience - no
     * dashboard shortcut, no sign-out control, no authenticated navigation -
     * whether reached fresh, or by a guest who was never signed in at all.
     */
    public function test_the_sign_in_page_shows_no_authenticated_controls_for_a_guest(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsStringIgnoringCase('go to dashboard', $html);
        $this->assertStringNotContainsStringIgnoringCase('sign out', $html);
        $this->assertStringNotContainsString('szSidebar', $html, 'the authenticated shell must not render on a guest page');
    }

    /**
     * A signed-in user hitting /login must follow the existing guest-route
     * behaviour (redirected to their dashboard) rather than being shown a
     * login form at all - the two must never be visible together.
     */
    public function test_an_authenticated_user_visiting_login_is_redirected_away(): void
    {
        $user = $this->userFor('student@scholarzim.co.zw');

        // The existing guest-route behaviour (RouteServiceProvider::HOME):
        // redirected to the public landing page, which itself offers "Go to
        // dashboard" - never shown the login form together with it.
        $this->actingAs($user)->get('/login')->assertRedirect('/');
    }

    /**
     * After signing out, the login page must show the same clean,
     * unauthenticated state as a guest who was never signed in - proving
     * logout actually clears the state the sign-in page reads, not just
     * that the guard reports guest().
     */
    public function test_after_sign_out_the_login_page_shows_no_authenticated_controls(): void
    {
        $this->post('/login', ['email' => 'student@scholarzim.co.zw', 'password' => self::PASSWORD]);
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsStringIgnoringCase('go to dashboard', $html);
        $this->assertStringNotContainsStringIgnoringCase('sign out', $html);
    }

    /**
     * The public landing page's own "Go to dashboard" / "Sign in" toggle
     * must track the real guard state, not a stale one - checked on both
     * sides of an actual sign-out so a regression here cannot hide behind
     * a fixture that happens to start logged out.
     */
    public function test_the_public_landing_page_reflects_the_real_guard_state(): void
    {
        $guestHtml = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Sign in', $guestHtml);
        $this->assertStringNotContainsStringIgnoringCase('go to dashboard', $guestHtml);

        $user = $this->userFor('student@scholarzim.co.zw');
        $authedHtml = $this->actingAs($user)->get('/')->assertOk()->getContent();
        $this->assertStringContainsStringIgnoringCase('go to dashboard', $authedHtml);
    }

    // ------------------------------------------------------------- navigation --

    /** "Exit" was a second, redundant sign-out control - see partials/sidebar.blade.php. */
    public function test_exit_is_not_rendered_anywhere_in_the_authenticated_shell(): void
    {
        $user = $this->userFor('student@scholarzim.co.zw');

        $html = $this->actingAs($user)->get('/applicant/dashboard')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/>\s*Exit\s*</', $html);
    }

    /** The single remaining sign-out control lives in the username menu. */
    public function test_sign_out_remains_available_from_the_username_menu(): void
    {
        $user = $this->userFor('student@scholarzim.co.zw');

        $html = $this->actingAs($user)->get('/applicant/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('Sign out', $html);
        $this->assertMatchesRegularExpression(
            '/action="[^"]*\/logout"[^>]*>.*?<button[^>]*>\s*Sign out\s*<\/button>/s',
            $html,
            'Sign out must submit to the logout route'
        );
    }

    // ------------------------------------------------------------- helpers --

    private function userFor(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }
}
