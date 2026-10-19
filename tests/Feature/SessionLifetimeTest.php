<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Closing the browser ends the session.
 *
 * A server cannot see a browser close, and these tests do not pretend it can: what is guaranteed is that the session
 * cookie ScholarZim sets has no expiry date (a "session cookie"), which is what makes the browser discard it when its
 * session ends. "The browser was closed" is therefore simulated the only honest way - the next request arrives
 * without the cookie. Nothing relies on unload/beforeunload JavaScript.
 *
 * Browsers that restore the previous session on start-up restore session cookies as well; that is a browser setting
 * and is documented in docs/security.md rather than asserted here.
 */
class SessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'ChangeMe123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function login(array $extra = [])
    {
        return $this->post('/login', array_merge(['email' => 'student@scholarzim.co.zw', 'password' => self::PASSWORD], $extra));
    }

    private function cookie($response, string $name): ?Cookie
    {
        return collect($response->headers->getCookies())->first(fn (Cookie $c) => $c->getName() === $name);
    }

    /** A request carrying only the session cookie a login handed out - the browser still open. */
    private function withSessionCookie(Cookie $cookie): static
    {
        // The value is already encrypted by the response; send it back untouched.
        return $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    /**
     * The browser was closed: the session cookie is gone, and nothing else identifies the person. The test client keeps
     * cookies between requests, so they are dropped, along with the user the auth guard remembered in memory.
     */
    private function closeBrowser(): static
    {
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];
        $this->flushSession();
        Auth::forgetGuards();

        return $this;
    }

    // ------------------------------------------------------------ configuration --

    public function test_the_session_is_configured_to_expire_when_the_browser_closes(): void
    {
        $this->assertTrue(config('session.expire_on_close'));
    }

    public function test_the_default_is_on_and_the_environment_can_still_switch_it_off(): void
    {
        $source = file_get_contents(config_path('session.php'));

        $this->assertMatchesRegularExpression("/'expire_on_close'\s*=>\s*env\('SESSION_EXPIRE_ON_CLOSE',\s*true\)/", $source);
    }

    public function test_the_environment_examples_and_deployment_files_ask_for_it(): void
    {
        foreach (['.env.example', 'render.yaml', 'docker-compose.prod.yml'] as $file) {
            $this->assertStringContainsString('SESSION_EXPIRE_ON_CLOSE', file_get_contents(base_path($file)), $file);
        }

        $this->assertMatchesRegularExpression('/key: SESSION_EXPIRE_ON_CLOSE\s+value: "true"/', file_get_contents(base_path('render.yaml')));
    }

    // ------------------------------------------------------------ the cookie --

    public function test_the_session_cookie_has_no_expiry_so_the_browser_drops_it_on_close(): void
    {
        $cookie = $this->cookie($this->login(), config('session.cookie'));

        $this->assertNotNull($cookie, 'signing in sets the session cookie');
        $this->assertSame(0, $cookie->getExpiresTime(), 'no expiry date: a session cookie');
    }

    public function test_every_response_that_sets_the_session_cookie_keeps_it_a_session_cookie(): void
    {
        foreach (['/', '/login', '/scholarships'] as $path) {
            $cookie = $this->cookie($this->get($path), config('session.cookie'));

            $this->assertNotNull($cookie, $path);
            $this->assertSame(0, $cookie->getExpiresTime(), $path);
        }
    }

    public function test_the_setting_is_what_drives_the_cookie(): void
    {
        config(['session.expire_on_close' => false]);

        $cookie = $this->cookie($this->get('/login'), config('session.cookie'));

        $this->assertGreaterThan(time(), $cookie->getExpiresTime(), 'switched off, the cookie outlives the browser for the lifetime');
    }

    public function test_the_cookie_keeps_its_other_protections(): void
    {
        $cookie = $this->cookie($this->login(), config('session.cookie'));

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    // ------------------------------------------------------------- no persistent login --

    public function test_a_sign_in_sets_no_remember_me_cookie_and_stores_no_token(): void
    {
        $response = $this->login();

        $this->assertNull($this->cookie($response, Auth::guard('web')->getRecallerName()));
        $this->assertNull(User::where('email', 'student@scholarzim.co.zw')->value('remember_token'));
    }

    public function test_asking_to_be_remembered_changes_nothing(): void
    {
        // An old form, a script, a hand-built request: the field is simply not read.
        $response = $this->login(['remember' => '1']);

        $this->assertNull($this->cookie($response, Auth::guard('web')->getRecallerName()), 'no persistent cookie is issued');
        $this->assertNull(User::where('email', 'student@scholarzim.co.zw')->value('remember_token'), 'no token is stored');
        $this->assertSame(0, $this->cookie($response, config('session.cookie'))->getExpiresTime(), 'the session cookie is still a session cookie');
    }

    public function test_the_only_credential_cookie_on_the_way_in_is_the_session_cookie(): void
    {
        $response = $this->login(['remember' => '1']);
        $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());

        $this->assertSame(0, $this->cookie($response, config('session.cookie'))->getExpiresTime(), 'it does not outlive the browser session');

        // XSRF-TOKEN is the CSRF double-submit token Laravel always sends; it cannot authenticate anyone.
        foreach ($names as $name) {
            $this->assertContains($name, [config('session.cookie'), 'XSRF-TOKEN'], "unexpected cookie $name");
            $this->assertStringStartsNotWith('remember_', $name);
        }
    }

    public function test_the_sign_in_form_no_longer_offers_to_keep_you_signed_in(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="remember"', $html);
        $this->assertStringNotContainsStringIgnoringCase('keep me signed in', $html);
        $this->assertStringContainsString('Forgot your password?', $html, 'the rest of the form is intact');
    }

    public function test_a_remember_cookie_from_before_is_refused_once_the_migration_has_run(): void
    {
        $user = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        // A browser that was "remembered" under the old behaviour: log in with remember through the guard and keep
        // the cookie it queued, as that browser would have.
        Auth::guard('web')->login($user, true);
        $recaller = collect(app('cookie')->getQueuedCookies())->first(fn ($c) => $c->getName() === Auth::guard('web')->getRecallerName());
        $this->assertNotNull($recaller, 'precondition: the old behaviour issued a remember cookie');
        $this->assertNotNull($user->fresh()->remember_token, 'and stored its token');

        $legacy = fn () => $this->closeBrowser()->withCookie($recaller->getName(), $recaller->getValue());

        $legacy()->get('/applicant/dashboard')->assertOk();   // why the migration is needed: that cookie signs them back in

        (require database_path('migrations/2025_01_01_000022_clear_remember_me_tokens.php'))->up();

        $this->assertNull($user->fresh()->remember_token);
        $legacy()->get('/applicant/dashboard')->assertRedirect(route('login'));
    }

    public function test_the_migration_clears_every_token_and_nothing_else(): void
    {
        $user = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $user->forceFill(['remember_token' => 'abc123'])->save();
        $hash = $user->password_hash;

        (require database_path('migrations/2025_01_01_000022_clear_remember_me_tokens.php'))->up();

        $this->assertSame(0, User::whereNotNull('remember_token')->count());
        $this->assertSame($hash, $user->fresh()->password_hash, 'passwords are untouched');
        $this->assertSame('student@scholarzim.co.zw', $user->fresh()->email);
    }

    // ------------------------------------------- during and after a browser session --

    public function test_a_signed_in_person_can_move_around_during_the_browser_session(): void
    {
        $cookie = $this->cookie($this->login(), config('session.cookie'));

        foreach (['/applicant/dashboard', '/applicant/profile', '/applicant/recommendations'] as $path) {
            $this->withSessionCookie($cookie)->get($path)->assertOk();
        }
    }

    public function test_once_the_browser_session_has_ended_the_next_visit_must_sign_in(): void
    {
        $cookie = $this->cookie($this->login(), config('session.cookie'));
        $this->withSessionCookie($cookie)->get('/applicant/dashboard')->assertOk();

        $this->closeBrowser();

        foreach (['/applicant/dashboard', '/applicant/profile', '/applicant/recommendations', '/account/security'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_the_landing_page_follows_the_current_state_not_a_stale_one(): void
    {
        $cookie = $this->cookie($this->login(), config('session.cookie'));

        $this->withSessionCookie($cookie)->get('/')->assertOk()->assertSee('Go to dashboard');

        $this->closeBrowser()->get('/')->assertOk()->assertDontSee('Go to dashboard')->assertSee('Sign in');
    }

    public function test_the_landing_page_is_not_stored_by_the_browser_cache(): void
    {
        $this->get('/')->assertHeader('Cache-Control')
            ->assertSee('Sign in');

        $this->assertStringContainsString('no-store', (string) $this->get('/')->headers->get('Cache-Control'));
    }

    // ------------------------------------------------------ the existing guarantees --

    public function test_the_session_id_changes_on_sign_in(): void
    {
        $before = $this->get('/login')->headers->getCookies();
        $beforeId = session()->getId();

        $this->login();

        $this->assertNotSame($beforeId, session()->getId());
        $this->assertNotEmpty($before);
    }

    public function test_signing_out_invalidates_the_session_and_regenerates_the_token(): void
    {
        $this->login();
        $oldId = session()->getId();
        $oldToken = session()->token();

        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame($oldId, session()->getId(), 'the session is replaced');
        $this->assertNotSame($oldToken, session()->token(), 'the CSRF token is regenerated');
    }

    public function test_the_old_cookie_is_useless_after_signing_out(): void
    {
        $cookie = $this->cookie($this->login(), config('session.cookie'));

        $this->withSessionCookie($cookie)->post('/logout')->assertRedirect(route('login'));

        // Replaying the cookie from before the sign-out reaches nothing.
        $this->withSessionCookie($cookie)->get('/applicant/dashboard')->assertRedirect(route('login'));
    }

    public function test_dashboards_and_account_pages_are_protected_after_signing_out(): void
    {
        $this->login();
        $this->post('/logout');

        foreach (['/dashboard', '/applicant/dashboard', '/applicant/profile', '/account/security', '/my-applications'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    // ---------------------------------- a closed page: the browser side of "the session ends when you leave" --

    public function test_every_page_tells_the_session_guard_whether_it_is_signed_in(): void
    {
        $guest = $this->get('/login')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-sz-auth="0"[^>]*data-sz-login="[^"]*\/login"/', $guest);

        $public = $this->get('/scholarships')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-sz-auth="0"/', $public);

        $this->login();
        $this->assertMatchesRegularExpression('/<html[^>]*data-sz-auth="1"[^>]*data-sz-login="[^"]*\/login"/', $this->get('/applicant/dashboard')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/<html[^>]*data-sz-auth="1"/', $this->get('/scholarships')->assertOk()->getContent());
    }

    public function test_the_guard_runs_in_the_head_before_the_page_is_drawn_and_needs_no_inline_script(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $head = explode('</head>', $html)[0];
        $this->assertStringContainsString('assets/js/session-guard.js', $head, 'in the head, so it runs before first paint');
        $this->assertStringNotContainsString('defer', substr($head, strpos($head, 'session-guard.js') - 80, 120));
        $this->assertStringContainsString("script-src 'self'", (string) $this->get('/login')->headers->get('Content-Security-Policy'));
        $this->assertFileExists(public_path('assets/js/session-guard.js'));
    }

    public function test_the_guard_is_served_and_asks_the_other_tabs_before_signing_out(): void
    {
        $script = (string) file_get_contents(public_path('assets/js/session-guard.js'));

        $this->assertStringContainsString('BroadcastChannel', $script);
        $this->assertStringContainsString('sessionStorage', $script);
        $this->assertStringContainsString("data-sz-login", $script);
        $this->assertStringContainsString("style.visibility = 'hidden'", $script, 'a protected page is not drawn while it asks');
    }
}
