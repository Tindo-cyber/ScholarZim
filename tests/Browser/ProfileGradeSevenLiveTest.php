<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Grade 7 results are for Primary applicants only. The server stops offering the Grade 7 qualification to
 * anyone above Primary (covered by AcademicProfileTest); what only a real browser can show is the form
 * following the "Current education level" choice live, including for rows added afterwards.
 */
class ProfileGradeSevenLiveTest extends DuskTestCase
{
    /** The Grade 7 choices nobody has selected: a row that already holds Grade 7 results keeps showing them. */
    private const PRIMARY_OPTION = '#academic-results option[data-primary-only]:not(:checked)';

    private function pupil(): User
    {
        return User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
    }

    /** Whether every Grade 7 option on the page is currently hidden. */
    private function hidden(Browser $browser): bool
    {
        return (bool) $browser->script(
            "var o = document.querySelectorAll('" . self::PRIMARY_OPTION . "');"
            . "return o.length > 0 && Array.prototype.every.call(o, function (x) { return x.hidden && x.disabled; });"
        )[0];
    }

    private function shown(Browser $browser): bool
    {
        return (bool) $browser->script(
            "var o = document.querySelectorAll('" . self::PRIMARY_OPTION . "');"
            . "return o.length > 0 && Array.prototype.every.call(o, function (x) { return ! x.hidden && ! x.disabled; });"
        )[0];
    }

    public function test_choosing_a_level_above_primary_hides_grade_7_and_primary_brings_it_back(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->pupil())->visit('/applicant/profile')
                ->click('#add-academic-result')->pause(300);   // a fresh row, so there is an unselected Grade 7 option

            // A Primary pupil sees it.
            $this->assertTrue($this->shown($browser), 'Grade 7 is offered at Primary');

            $browser->select('#sz-education-level', 'O_LEVEL');
            $this->assertTrue($this->hidden($browser), 'hidden once the level is O-Level');

            $browser->select('#sz-education-level', 'UNDERGRADUATE');
            $this->assertTrue($this->hidden($browser), 'and for every other level');

            $browser->select('#sz-education-level', 'PRIMARY');
            $this->assertTrue($this->shown($browser), 'back at Primary');
        });
    }

    public function test_a_row_added_after_the_level_changed_does_not_offer_grade_7_either(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->pupil())->visit('/applicant/profile')
                ->select('#sz-education-level', 'A_LEVEL');

            $browser->click('#add-academic-result')->pause(300);

            $this->assertTrue($this->hidden($browser), 'the new row\'s Grade 7 option is hidden too');
        });
    }
}
