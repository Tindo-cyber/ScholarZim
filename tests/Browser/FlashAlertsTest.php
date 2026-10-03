<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * resources/js/flash-alerts.js: a success flash disappears on its own after
 * a few seconds, instead of sitting on screen until the reader clicks its
 * close button - the behaviour requested after a provider watched "...is now
 * live on the public site" linger on the admin dashboard. An error or the
 * validation summary is deliberately exempt - see the script's own comment -
 * so these tests prove both halves: the success message goes away by
 * itself, and an error one does not.
 */
class FlashAlertsTest extends DuskTestCase
{
    /** logout() flashes a plain successMessage and redirects to '/' - the simplest real trigger that needs no auth setup. */
    public function test_a_success_message_disappears_on_its_own(): void
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

            $browser->pause(800)
                ->assertVisible('.alert-success')
                ->assertSee('You have been signed out.');

            $browser->pause(6500);

            $this->assertCount(
                0,
                $browser->elements('.alert-success'),
                'a success flash must disappear on its own without the reader clicking its close button'
            );
        });
    }

    /** showResetForm() flashes an errorMessage for an invalid token - no auth needed either. */
    public function test_an_error_message_does_not_disappear_on_its_own(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/reset-password/not-a-real-token')
                ->pause(500)
                ->assertVisible('.alert-danger');

            $browser->pause(6500);

            $this->assertGreaterThan(
                0,
                count($browser->elements('.alert-danger')),
                'an error must stay until the reader dismisses it themselves, not race a timer'
            );
        });
    }
}
