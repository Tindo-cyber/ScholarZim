<?php

namespace Tests\Browser;

use Illuminate\Support\Facades\Cache;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DuskTestCase;

/**
 * Sign-in, sign-out, Back and Forward in a real browser, for every role.
 *
 * tests/Feature/RoleSessionIsolationTest.php proves the server side for
 * Applicant, Provider and Admin. This drives the actual UI - the sign-in form,
 * the account menu's "Sign out", and the browser's own history buttons - because
 * Back and Forward are browser behaviour that an HTTP-level test can only model.
 */
class RoleSessionBrowserTest extends DuskTestCase
{
    private const PASSWORD = 'ChangeMe123';

    private const MENU = '[aria-label="Account menu"]';

    /**
     * Seconds each wait may take. A sign-in costs about two seconds on its own
     * (the password hash) before the redirect and page render, so Dusk's default
     * five-second wait failed on a busy machine. The waits return the moment the
     * condition is met, so a generous ceiling costs nothing when things are fast.
     */
    private const WAIT = 20;

    /**
     * Sign-in is throttled per IP (10 a minute) and per email (5 failures), and
     * every one of these tests signs in several times from the same address.
     * The counters live in the cache the running server shares, so they are
     * cleared before each test: the throttle itself is covered in
     * AuthenticationTest, not here.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** Leave no throttle counts behind for whichever test or run comes next. */
    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> role => [email, dashboard, a second protected page] */
    public static function roles(): array
    {
        return [
            'applicant' => ['student@scholarzim.co.zw', '/applicant/dashboard', '/applicant/profile'],
            'provider' => ['provider@scholarzim.co.zw', '/provider/dashboard', '/provider/applications'],
            'admin' => ['admin@scholarzim.co.zw', '/admin/dashboard', '/admin/users'],
        ];
    }

    #[DataProvider('roles')]
    public function test_back_and_forward_after_signing_out_never_show_a_protected_page(string $email, string $dashboard, string $second): void
    {
        $this->browse(function (Browser $browser) use ($email, $dashboard, $second) {
            $this->freshBrowser($browser);
            $this->signIn($browser, $email, $dashboard);
            $browser->visit($second)->assertPresent(self::MENU);

            $this->signOut($browser);
            $browser->assertPathIs('/login')->assertPresent('input[name="password"]');

            // Back: the page the browser last showed.
            $browser->back()->pause(1500);
            $this->assertShowsSignIn($browser, 'Back after sign-out');

            // Forward, and Back again, both stay on the sign-in page.
            $browser->forward()->pause(1500);
            $this->assertShowsSignIn($browser, 'Forward after sign-out');

            // Typing the protected URL straight in is refused too.
            foreach ([$dashboard, $second] as $path) {
                $browser->visit($path)->assertPathIs('/login')->assertMissing(self::MENU);
            }
        });
    }

    #[DataProvider('roles')]
    public function test_going_back_to_the_sign_in_page_ends_the_session_and_forward_asks_for_credentials(string $email, string $dashboard): void
    {
        $this->browse(function (Browser $browser) use ($email, $dashboard) {
            $this->freshBrowser($browser);
            $browser->visit('/login');
            $this->signIn($browser, $email, $dashboard);

            // Back to the sign-in page: the form, with the session ended.
            $browser->back()->pause(1500);
            $this->assertShowsSignIn($browser, 'Back to the sign-in page');

            // Forward to the dashboard: credentials are required again.
            $browser->forward()->pause(1500);
            $browser->assertPathIs('/login')->assertMissing(self::MENU);
            $browser->visit($dashboard)->assertPathIs('/login');
        });
    }

    #[DataProvider('roles')]
    public function test_signing_in_again_after_signing_out_reaches_only_that_roles_area(string $email, string $dashboard, string $second): void
    {
        $this->browse(function (Browser $browser) use ($email, $dashboard, $second) {
            $this->freshBrowser($browser);
            $this->signIn($browser, $email, $dashboard);
            $this->signOut($browser);

            $this->signIn($browser, $email, $dashboard);
            $browser->visit($second)->assertPathIs($second)->assertPresent(self::MENU);
        });
    }

    /** A wrong password typed over a live session ends it rather than keeping the old one. */
    #[DataProvider('roles')]
    public function test_a_failed_sign_in_from_a_live_session_leaves_nobody_signed_in(string $email, string $dashboard): void
    {
        $this->browse(function (Browser $browser) use ($email, $dashboard) {
            $this->freshBrowser($browser);
            $this->signIn($browser, $email, $dashboard);

            $this->fillSignInForm($browser->visit('/login'), $email, 'not-the-password');

            $browser->press('Sign in')
                ->waitForText('Incorrect email or password.', self::WAIT)
                ->assertPathIs('/login');

            $browser->visit($dashboard)->assertPathIs('/login')->assertMissing(self::MENU);
        });
    }

    /** One browser, three roles in a row: nothing of the previous role survives. */
    public function test_one_browser_signing_in_as_each_role_in_turn_keeps_them_isolated(): void
    {
        $this->browse(function (Browser $browser) {
            $this->freshBrowser($browser);

            $chain = [
                ['student@scholarzim.co.zw', '/applicant/dashboard', ['/provider/dashboard', '/admin/dashboard']],
                ['provider@scholarzim.co.zw', '/provider/dashboard', ['/admin/dashboard', '/applicant/dashboard']],
                ['admin@scholarzim.co.zw', '/admin/dashboard', ['/provider/dashboard', '/applicant/dashboard']],
                ['student@scholarzim.co.zw', '/applicant/dashboard', ['/provider/dashboard', '/admin/dashboard']],
            ];

            foreach ($chain as [$email, $dashboard, $forbidden]) {
                $this->signIn($browser, $email, $dashboard);

                foreach ($forbidden as $path) {
                    $browser->visit($path)->assertSee('403');
                }

                // The 403 page has no account menu; return to the role's own area first.
                $browser->visit($dashboard)->assertPresent(self::MENU);

                $this->signOut($browser);

                // Back lands on a page that demands credentials; the next role starts clean.
                $browser->back()->pause(1500);
                $this->assertShowsSignIn($browser, "Back after $email signed out");
            }
        });
    }

    // ----------------------------------------------------------- helpers -------

    private function freshBrowser(Browser $browser): void
    {
        $browser->driver->manage()->deleteAllCookies();
    }

    private function signIn(Browser $browser, string $email, string $dashboard): void
    {
        $this->fillSignInForm($browser->visit('/login'), $email, self::PASSWORD);

        $browser->press('Sign in')
            ->waitForLocation($dashboard, self::WAIT)
            ->assertPathIs($dashboard)
            ->assertPresent(self::MENU);
    }

    /**
     * Types into the sign-in form and confirms the browser actually took the text.
     *
     * The email field is autofocused, and on a busy machine keystrokes sent right
     * after the page loads were sometimes dropped. The form then submitted empty,
     * the browser's own `required` check refused it, and no request ever reached
     * the server - a test timeout that looked like an application fault. Verifying
     * the values (and retrying) removes that without hiding a real failure: if the
     * form genuinely cannot be filled, this still fails.
     */
    private function fillSignInForm(Browser $browser, string $email, string $password): void
    {
        $browser->waitFor('input[name="email"]', self::WAIT)->waitFor('input[name="password"]', self::WAIT);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $browser->type('email', $email)->type('password', $password);

            if ($browser->inputValue('email') === $email && $browser->inputValue('password') === $password) {
                return;
            }

            $browser->pause(500);
        }

        $browser->assertInputValue('email', $email);
        $browser->assertInputValue('password', $password);
    }

    private function signOut(Browser $browser): void
    {
        $browser->click(self::MENU)
            ->waitFor('.dropdown-menu.show', self::WAIT)
            ->click('.dropdown-menu.show .text-danger')
            ->waitForLocation('/login', self::WAIT);
    }

    /** The browser is on the real sign-in form, with no signed-in shell. */
    private function assertShowsSignIn(Browser $browser, string $when): void
    {
        $browser->assertPathIs('/login');
        $browser->assertPresent('input[name="password"]');
        $browser->assertMissing(self::MENU);

        $this->assertStringNotContainsStringIgnoringCase('go to dashboard', $browser->driver->getPageSource(), $when);
    }
}
