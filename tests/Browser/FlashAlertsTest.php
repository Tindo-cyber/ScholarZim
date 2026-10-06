<?php

namespace Tests\Browser;

use Illuminate\Support\Facades\Cache;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * resources/js/flash-alerts.js: a session flash - success or error - is
 * temporary feedback and disappears on its own two seconds after it appears,
 * without the reader clicking its close button. The validation summary is
 * deliberately exempt: it lists fields still to fix, so it stays until the
 * user acts.
 */
class FlashAlertsTest extends DuskTestCase
{
    /**
     * The validation-summary test submits a bad sign-in, and sign-in is throttled
     * per IP (10 a minute) in a cache the running server shares. Leftover counts
     * from earlier runs turned that submit into a "429 Too Many Requests" page
     * with no summary, so start each test from a clean throttle.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** logout() flashes a plain successMessage and redirects to the sign-in page - no auth setup needed. */
    public function test_a_success_message_disappears_after_two_seconds(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')->pause(300);

            $token = $browser->script("return document.querySelector('meta[name=csrf-token]').content;")[0];
            $browser->script("
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = '/logout';
                const i = document.createElement('input');
                i.name = '_token';
                i.value = " . json_encode($token) . ";
                f.appendChild(i);
                document.body.appendChild(f);
                f.submit();
            ");

            // waitFor returns the moment the alert exists, so it cannot overshoot
            // the alert's own two-second dismissal the way a long pause could.
            $browser->waitFor('.alert-success', 6)
                ->assertPathIs('/login')
                ->assertVisible('.alert-success')
                ->assertSee('You have been signed out.');

            // Hovering it no longer holds it on screen.
            $browser->mouseover('.alert-success');

            $browser->pause(2000);

            $this->assertCount(
                0,
                $browser->elements('.alert-success'),
                'a success flash must disappear two seconds after it appears'
            );
        });
    }

    /** showResetForm() flashes an errorMessage for an invalid token - no auth needed either. */
    public function test_a_session_error_message_disappears_after_two_seconds(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/reset-password/not-a-real-token')
                ->pause(500)
                ->assertVisible('.alert-danger[data-sz-autodismiss]');

            $browser->pause(2000);

            $this->assertCount(
                0,
                $browser->elements('.alert-danger[data-sz-autodismiss]'),
                'a session error flash is temporary feedback and must disappear on its own'
            );
        });
    }

    /** A rejected sign-in produces the validation summary - which must stay. */
    public function test_the_validation_summary_does_not_disappear_on_its_own(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->waitFor('input[name="email"]', 10)
                ->waitFor('input[name="password"]', 10);

            // Keystrokes sent right after load are occasionally dropped on a busy
            // machine, which leaves the form empty and the browser refusing to
            // submit it. Confirm the text landed, retrying, so a missing summary
            // can only mean the summary itself is missing.
            for ($attempt = 1; $attempt <= 4; $attempt++) {
                $browser->type('email', 'nobody@example.test')->type('password', 'not-the-password');

                if ($browser->inputValue('email') === 'nobody@example.test' && $browser->inputValue('password') === 'not-the-password') {
                    break;
                }

                $browser->pause(500);
            }

            $browser->press('Sign in')
                // Waits for the page to arrive rather than guessing how long a
                // busy machine takes; the persistence check below is unchanged.
                ->waitForText('Please fix the following:', 10)
                ->assertSee('Please fix the following:');

            $browser->pause(3500)
                ->assertSee('Please fix the following:');
        });
    }
}
