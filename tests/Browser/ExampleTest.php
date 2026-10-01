<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Dusk's own generated scaffold asserted "Laravel" appears on the homepage -
 * true of the framework's default welcome page, not of this one. Kept as a
 * real smoke test instead of deleting it: a guest visiting the actual
 * ScholarZim homepage, in a real browser, with the built JS/CSS loaded.
 */
class ExampleTest extends DuskTestCase
{
    public function test_a_guest_can_load_the_homepage(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->assertSee('ScholarZim');
        });
    }
}
