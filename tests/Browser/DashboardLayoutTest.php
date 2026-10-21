<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A stat card is h-100 so a row of them is equally tall. Stacked in the dashboard's right-hand column, that made the first
 * card as tall as the whole column and pushed the cards after it out past the footer. Layout is only real in a browser.
 */
class DashboardLayoutTest extends DuskTestCase
{
    public function test_nothing_on_the_student_dashboard_hangs_below_the_page_footer(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($student) {
            $browser->resize(1352, 679)->loginAs($student)->visit('/applicant/dashboard')->pause(500);

            $overhang = $browser->script(
                "var col = document.querySelector('.sz-page .col-xl-4');"
                . "var last = col.lastElementChild.getBoundingClientRect().bottom;"
                . "return Math.round(last - col.getBoundingClientRect().bottom);"
            )[0];

            $this->assertLessThanOrEqual(1, $overhang, 'the last card in the right-hand column sticks out below its column');
        });
    }
}
