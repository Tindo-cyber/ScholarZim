<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\RoleNames;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sign-in, sign-out and the browser's Back/Forward buttons, for every role.
 *
 * Applicant, Provider and Admin share one session/cache implementation
 * (LoginController, the `auth` middleware and the `cache.headers:no_store`
 * route-group middleware), so these tests run the same scenarios against all
 * three rather than trusting that a fix proven for one covers the others.
 *
 * Browser Back and Forward are modelled at the HTTP level the way a browser with
 * a `no-store` page behaves: it cannot reuse the cached page, so each history
 * step is a fresh request - which is what the assertions replay. The real
 * browser behaviour is exercised by tests/Browser/RoleSessionBrowserTest.php.
 */
class RoleSessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'ChangeMe123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: array<int, string>, 4: array<int, string>}> */
    public static function roles(): array
    {
        return [
            'applicant' => [
                RoleNames::APPLICANT,
                'student@scholarzim.co.zw',
                '/applicant/dashboard',
                ['/applicant/dashboard', '/applicant/profile', '/applicant/recommendations', '/applicant/saved', '/notifications', '/account/security'],
                ['/provider/dashboard', '/admin/dashboard', '/admin/users'],
            ],
            'provider' => [
                RoleNames::PROVIDER,
                'provider@scholarzim.co.zw',
                '/provider/dashboard',
                ['/provider/dashboard', '/provider/applications', '/provider/analytics', '/notifications', '/account/security'],
                ['/admin/dashboard', '/admin/users', '/applicant/dashboard', '/applicant/profile'],
            ],
            'admin' => [
                RoleNames::ADMIN,
                'admin@scholarzim.co.zw',
                '/admin/dashboard',
                ['/admin/dashboard', '/admin/users', '/admin/audit-log', '/admin/analytics', '/admin/reports', '/notifications', '/account/security'],
                ['/provider/dashboard', '/provider/applications', '/applicant/dashboard', '/applicant/profile'],
            ],
        ];
    }

    // ------------------------------------------------------- headers ----------

    #[DataProvider('roles')]
    public function test_every_protected_page_is_served_no_store(string $role, string $email, string $dashboard, array $protected): void
    {
        $this->signIn($email);

        foreach ($protected as $path) {
            $response = $this->get($path)->assertOk();
            $cache = (string) $response->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $cache, "$role $path must be no-store, got: $cache");
            $this->assertStringContainsString('private', $cache, "$role $path must be private, got: $cache");
        }
    }

    #[DataProvider('roles')]
    public function test_the_signed_in_shell_and_sign_in_page_opt_out_of_the_bfcache(string $role, string $email, string $dashboard): void
    {
        $this->assertStringContainsString('data-sz-sensitive', $this->get('/login')->assertOk()->getContent());

        $this->signIn($email);
        $this->assertStringContainsString('data-sz-sensitive', $this->get($dashboard)->assertOk()->getContent());
    }

    public function test_public_pages_keep_the_bfcache(): void
    {
        $this->assertStringNotContainsString('data-sz-sensitive', $this->get('/')->assertOk()->getContent());
        $this->assertStringNotContainsString('data-sz-sensitive', $this->get('/scholarships')->assertOk()->getContent());
    }

    // ---------------------------------------- logout, Back, Forward -----------

    #[DataProvider('roles')]
    public function test_logout_then_back_and_forward_never_restore_a_protected_page(string $role, string $email, string $dashboard, array $protected): void
    {
        $this->signIn($email);
        $this->get($dashboard)->assertOk();
        $this->get($protected[1])->assertOk();

        $tokenBefore = csrf_token();
        $sessionBefore = session()->getId();

        $this->post('/logout')
            ->assertRedirect(route('login'))
            ->assertSessionHas('successMessage', 'You have been signed out.');

        $this->assertGuest();
        $this->assertNotSame($sessionBefore, session()->getId(), 'logout must invalidate the session');
        $this->assertNotSame($tokenBefore, csrf_token(), 'logout must regenerate the CSRF token');

        // Back: the dashboard the browser last showed. Forward: the other page it visited.
        foreach ([$dashboard, $protected[1]] as $historyEntry) {
            $response = $this->get($historyEntry)->assertRedirect(route('login'));
            $this->assertStringNotContainsString($email, (string) $response->getContent());
        }

        // And the sign-in page it lands on is the real form, not a dashboard.
        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringNotContainsStringIgnoringCase('go to dashboard', $html);
    }

    #[DataProvider('roles')]
    public function test_every_protected_url_requires_authentication_after_logout(string $role, string $email, string $dashboard, array $protected): void
    {
        $this->signIn($email);
        $this->post('/logout');

        foreach ($protected as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    // ------------------------------------------------- Back to /login ----------

    #[DataProvider('roles')]
    public function test_going_back_to_the_sign_in_page_ends_the_session(string $role, string $email, string $dashboard): void
    {
        $this->signIn($email);
        $this->get($dashboard)->assertOk();
        $before = session()->getId();

        $this->get('/login')->assertOk()->assertSee('name="password"', false);

        $this->assertGuest();
        $this->assertNotSame($before, session()->getId());
        $this->get($dashboard)->assertRedirect(route('login'));
    }

    // ------------------------------------------------------ re-login -----------

    #[DataProvider('roles')]
    public function test_signing_in_again_gives_a_fresh_session_and_the_right_dashboard(string $role, string $email, string $dashboard, array $protected): void
    {
        $this->signIn($email);
        session()->put('left-over', 'state from the first sign-in');
        $first = session()->getId();

        $this->post('/logout');
        $this->post('/login', ['email' => $email, 'password' => self::PASSWORD])->assertRedirect($dashboard);

        $this->assertAuthenticatedAs(User::where('email', $email)->firstOrFail());
        $this->assertNotSame($first, session()->getId());
        $this->assertNull(session('left-over'));

        foreach ($protected as $path) {
            $this->get($path)->assertOk();
        }
    }

    #[DataProvider('roles')]
    public function test_a_sign_in_submitted_from_a_live_session_replaces_it(string $role, string $email, string $dashboard): void
    {
        $this->signIn($email);
        session()->put('left-over', 'state from the first sign-in');
        $first = session()->getId();

        $this->post('/login', ['email' => $email, 'password' => self::PASSWORD])->assertRedirect($dashboard);

        $this->assertAuthenticatedAs(User::where('email', $email)->firstOrFail());
        $this->assertNotSame($first, session()->getId());
        $this->assertNull(session('left-over'));
    }

    #[DataProvider('roles')]
    public function test_a_failed_sign_in_from_a_live_session_leaves_no_session(string $role, string $email, string $dashboard, array $protected): void
    {
        $this->signIn($email);

        $this->post('/login', ['email' => $email, 'password' => 'definitely-wrong'])
            ->assertSessionHasErrors(['email' => 'Incorrect email or password.']);

        $this->assertGuest();

        foreach ($protected as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    // ------------------------------------------- account switching ------------

    #[DataProvider('roles')]
    public function test_a_second_account_of_the_same_role_inherits_nothing_from_the_first(string $role, string $email, string $dashboard): void
    {
        $other = $this->secondUserFor($role, $email);

        $this->signIn($email);
        $first = User::where('email', $email)->firstOrFail();
        $note = $this->notifyUser($first, 'PRIVATE-NOTE-FOR-FIRST-' . $role);
        session()->put('left-over', 'first account state');
        $this->assertStringContainsString($note, $this->get('/notifications')->assertOk()->getContent());

        // Switch without signing out - the sign-in form allows it.
        $this->post('/login', ['email' => $other->email, 'password' => self::PASSWORD])->assertRedirect($dashboard);

        $this->assertAuthenticatedAs($other);
        $this->assertNull(session('left-over'));
        $this->assertStringNotContainsString($note, $this->get('/notifications')->assertOk()->getContent());
        $this->get($dashboard)->assertOk();
    }

    // ------------------------------------------ cross-role isolation ----------

    /** @return array<string, array{0: string, 1: string}> every ordered pair of different roles */
    public static function rolePairs(): array
    {
        $names = array_keys(self::roles());
        $pairs = [];

        foreach ($names as $from) {
            foreach ($names as $to) {
                if ($from !== $to) {
                    $pairs["$from then $to"] = [$from, $to];
                }
            }
        }

        return $pairs;
    }

    #[DataProvider('rolePairs')]
    public function test_the_next_account_never_inherits_the_previous_roles_access_or_state(string $from, string $to): void
    {
        [, $fromEmail, $fromDashboard, $fromProtected, $fromForbidden] = self::roles()[$from];
        [, $toEmail, $toDashboard, $toProtected, $toForbidden] = self::roles()[$to];

        $fromUser = $this->signIn($fromEmail);
        $note = $this->notifyUser($fromUser, 'PRIVATE-NOTE-FOR-' . strtoupper($from));
        session()->put('left-over', "state from $from");
        $this->get($fromDashboard)->assertOk();
        $this->post('/logout')->assertRedirect(route('login'));

        // Whatever the first role could open is closed to the browser's history.
        foreach ($fromProtected as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }

        // The last history entry replayed is the previous role's own dashboard.
        $this->get($fromDashboard)->assertRedirect(route('login'));

        $toUser = $this->signIn($toEmail, $toDashboard);
        $this->assertAuthenticatedAs($toUser);
        $this->assertNull(session('left-over'));
        $this->assertNull(session('url.intended'), 'the previous role must not leave a post-login destination behind');

        // The new role gets exactly its own area...
        foreach ($toProtected as $path) {
            $this->get($path)->assertOk();
        }
        $this->assertStringNotContainsString($note, $this->get('/notifications')->assertOk()->getContent());

        // ...and none of the previous role's, nor anything else it was not given.
        foreach ($fromForbidden === [] ? [] : array_unique(array_merge($toForbidden, array_diff($fromProtected, $toProtected))) as $path) {
            if (str_starts_with($path, '/notifications') || str_starts_with($path, '/account')) {
                continue;
            }

            $this->get($path)->assertForbidden();
        }
    }

    // ---------------------------------------- where a sign-in lands -----------

    /** The page a guest was stopped at is still honoured - for the role that may open it. */
    #[DataProvider('roles')]
    public function test_a_guest_is_returned_to_the_page_they_were_stopped_at(string $role, string $email, string $dashboard, array $protected): void
    {
        $target = $protected[1];

        $this->get($target)->assertRedirect(route('login'));
        $this->post('/login', ['email' => $email, 'password' => self::PASSWORD])->assertRedirect($target);
    }

    /**
     * Back/Forward after signing out as another role leaves that role's page as
     * the "intended" destination. The next role must land on its own dashboard,
     * not on a 403 for somebody else's area - and the stale URL is consumed.
     */
    #[DataProvider('rolePairs')]
    public function test_another_roles_stopped_page_is_never_the_landing_page(string $from, string $to): void
    {
        [, , , $fromProtected] = self::roles()[$from];
        [, $toEmail, $toDashboard] = self::roles()[$to];

        $this->get($fromProtected[0])->assertRedirect(route('login'));
        $this->assertNotNull(session('url.intended'));

        $this->post('/login', ['email' => $toEmail, 'password' => self::PASSWORD])->assertRedirect($toDashboard);

        $this->assertNull(session('url.intended'));
        $this->get($toDashboard)->assertOk();
    }

    // ------------------------------------------------ authorisation -----------

    #[DataProvider('roles')]
    public function test_other_roles_areas_stay_forbidden_before_and_after_re_authentication(string $role, string $email, string $dashboard, array $protected, array $forbidden): void
    {
        $this->signIn($email);
        foreach ($forbidden as $path) {
            $this->get($path)->assertForbidden();
        }

        $this->post('/logout');
        foreach ($forbidden as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }

        $this->signIn($email, $dashboard);
        foreach ($forbidden as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    #[DataProvider('roles')]
    public function test_a_suspended_account_loses_its_live_session_and_cannot_sign_back_in(string $role, string $email, string $dashboard, array $protected): void
    {
        $user = $this->signIn($email);
        $this->get($dashboard)->assertOk();

        $user->forceFill(['account_status' => AccountStatus::SUSPENDED])->save();

        // In production every request loads the user from the database afresh;
        // the test client keeps the guard's cached user between requests, so
        // drop it to get the same behaviour.
        $this->app['auth']->forgetGuards();

        // The browser history entry is replayed: the session is gone, not merely hidden.
        $this->get($dashboard)->assertRedirect(route('login'));
        $this->assertGuest();

        foreach ($protected as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }

        $this->post('/login', ['email' => $email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ----------------------------------------------------------- helpers -------

    private function signIn(string $email, ?string $expectedRedirect = null): User
    {
        $this->post('/login', ['email' => $email, 'password' => self::PASSWORD])
            ->assertRedirect($expectedRedirect);

        $user = User::where('email', $email)->firstOrFail();
        $this->assertAuthenticatedAs($user);

        return $user;
    }

    private function notifyUser(User $user, string $message): string
    {
        Notification::create([
            'user_id' => $user->user_id,
            'type' => 'GENERAL',
            'message' => $message,
            'is_read' => false,
            'created_at' => now(),
        ]);

        return $message;
    }

    private function secondUserFor(string $role, string $firstEmail): User
    {
        $existing = [
            RoleNames::APPLICANT => 'chipo.ncube@scholarzim.co.zw',
            RoleNames::PROVIDER => 'trust@scholarzim.co.zw',
        ][$role] ?? null;

        if ($existing !== null) {
            $user = User::where('email', $existing)->firstOrFail();
        } else {
            $user = User::create([
                'role_id' => Role::where('role_name', $role)->value('role_id'),
                'full_name' => 'Second Administrator',
                'email' => 'second-admin@scholarzim.co.zw',
                'password_hash' => Hash::make(self::PASSWORD),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]);
        }

        $this->assertNotSame($firstEmail, $user->email);

        return $user;
    }
}
