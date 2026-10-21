<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * resources/js/phone-input.js keeps letters out of phone fields as they are typed. The server rules are covered by the
 * Feature tests; this proves the browser side runs on the registration forms.
 */
class PhoneInputSanitisationTest extends DuskTestCase
{
    public function test_the_student_phone_field_takes_digits_only(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/register')
                ->type('phone', '07a71-23 4b567')
                ->assertInputValue('phone', '0771234567');
        });
    }

    public function test_the_provider_phone_field_takes_digits_a_leading_plus_and_a_numeric_extension(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/register/provider')
                ->type('phone', '+44 20 abc 7946 0000')
                ->assertInputValue('phone', '+44 20  7946 0000')
                ->type('phone', '12+34abc')
                ->assertInputValue('phone', '1234')
                ->type('phone', '+263 24 277 1234 x12ab3')
                ->assertInputValue('phone', '+263 24 277 1234 x123');
        });
    }
}
