<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Sign in, close the page without signing out, open it again: the password is asked for.
 *
 * A server cannot see a page close, so the browser side (public/assets/js/session-guard.js) does it: a signed-in page in a tab
 * that has not been used, with no other ScholarZim page open, goes to the sign-in page, where the server ends the session.
 * "Closed" is simulated by dropping the tab's marker - what the browser does when a tab closes - since a test cannot
 * close the only window it is driving.
 */
class ClosedPageSessionBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    private function signIn(Browser $browser, User $user): Browser
    {
        $browser->visit('/login')->waitFor('input[name="email"]', self::WAIT);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $browser->type('email', $user->email)->type('password', 'ChangeMe123');

            if ($browser->inputValue('email') === $user->email && $browser->inputValue('password') === 'ChangeMe123') {
                break;
            }

            $browser->pause(500);
        }

        return $browser->press('Sign in')->waitUntilMissing('input[name="password"]', self::WAIT);
    }

    public function test_signing_in_and_moving_about_never_asks_again(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($student) {
            $this->signIn($browser, $student)
                ->assertPathIsNot('/login')
                ->visit('/applicant/profile')
                ->waitFor('#sz-profile-form', self::WAIT)
                ->visit('/applicant/dashboard')
                ->pause(900)
                ->assertPathIsNot('/login');
        });
    }

    public function test_a_page_opened_after_everything_was_closed_asks_for_the_password(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($student) {
            $this->signIn($browser, $student)->assertPathIsNot('/login');

            // The page is closed: its tab's marker goes with it. Opening it again is a request with the old cookie.
            $browser->script("window.sessionStorage.removeItem('sz-tab');");
            $browser->visit('/applicant/profile');

            $browser->waitForLocation('/login', self::WAIT)
                ->assertPathIs('/login')
                ->assertPresent('input[name=password]');

            // And it really is signed out: the protected page now needs the password.
            $browser->visit('/applicant/profile')->waitForLocation('/login', self::WAIT)->assertPathIs('/login');
        });
    }

    public function test_a_second_tab_beside_one_that_is_still_open_stays_signed_in(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($student) {
            $this->signIn($browser, $student)->assertPathIsNot('/login');

            // A new tab that does not inherit the first one's storage, as a tab opened from the address bar does not.
            $first = $browser->driver->getWindowHandle();
            $browser->script("window.open('/applicant/profile', '_blank', 'noopener');");
            $browser->pause(1500);

            $handles = $browser->driver->getWindowHandles();
            $second = collect($handles)->first(fn ($h) => $h !== $first);
            $this->assertNotNull($second, 'a second tab opened');

            $browser->driver->switchTo()->window($second);
            $browser->waitFor('#sz-profile-form', self::WAIT);
            $this->assertStringContainsString('/applicant/profile', $browser->driver->getCurrentURL(), 'still signed in beside the open one');

            $browser->driver->close();
            $browser->driver->switchTo()->window($first);
        });
    }

    /** Sets the "every page was last alive" stamp from a page that has no guard, so leaving the app does not refresh it. */
    private function ageTheStamp(Browser $browser, int $secondsAgo): void
    {
        $browser->visit('/assets/js/theme-toggle.js');
        $browser->script("window.localStorage.setItem('sz-seen', String(Date.now() - " . ($secondsAgo * 1000) . "));");
    }

    public function test_a_tab_restored_after_every_page_was_gone_for_a_while_asks_for_the_password(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($student) {
            $this->signIn($browser, $student)->assertPathIsNot('/login');

            // The browser was closed and its tab restored with its storage: the marker is still there, but nothing of ours
            // has been alive for minutes.
            $this->ageTheStamp($browser, 600);
            $browser->visit('/applicant/profile');

            $browser->waitForLocation('/login', self::WAIT)->assertPathIs('/login')->assertPresent('input[name=password]');
        });
    }

    public function test_a_short_gap_between_pages_never_asks_again(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($student) {
            $this->signIn($browser, $student)->assertPathIsNot('/login');

            // A slow page or a brief pause is not a closed browser.
            $this->ageTheStamp($browser, 10);
            $browser->visit('/applicant/profile')->waitFor('#sz-profile-form', self::WAIT);
            $browser->pause(900)->assertPathIs('/applicant/profile');
        });
    }
}
