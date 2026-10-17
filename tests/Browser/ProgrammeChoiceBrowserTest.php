<?php

namespace Tests\Browser;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\User;
use App\Support\EducationLevel;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * resources/js/limited-choice.js: the "tick up to three" lists. The HTTP-level suite (ProgrammeSelectionAndWebsiteTest)
 * proves the server refuses a fourth; this proves the page itself stops one being ticked, counts as you go, lets a choice
 * be swapped, and saves the whole selection in one submission.
 */
class ProgrammeChoiceBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    private const LIST = '#intended_programme_ids';

    public function test_a_fourth_programme_cannot_be_ticked_and_a_choice_can_be_swapped_before_saving(): void
    {
        $student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $student->user_id)->firstOrFail();
        $profile->forceFill(['education_level' => EducationLevel::A_LEVEL])->save();
        ApplicantProgramme::where('profile_id', $profile->profile_id)->delete();

        $this->browse(function (Browser $browser) use ($student, $profile) {
            $browser->loginAs($student)
                ->visit('/applicant/profile')
                ->waitFor(self::LIST . ' [data-limited-count]', self::WAIT)
                ->assertSeeIn(self::LIST . ' [data-limited-count]', '0 of 3 programmes chosen');

            $boxes = self::LIST . ' input[type=checkbox]';
            $ids = $browser->script("return Array.from(document.querySelectorAll('" . $boxes . "')).slice(0, 4).map(function (b) { return b.id; });")[0];

            foreach (array_slice($ids, 0, 3) as $id) {
                $browser->check('#' . $id);
            }

            $browser->assertSeeIn(self::LIST . ' [data-limited-count]', '3 of 3 programmes chosen')
                ->assertDisabled('#' . $ids[3]);

            // Untick one: the rest come back, so another can take its place.
            $browser->uncheck('#' . $ids[0])
                ->assertEnabled('#' . $ids[3])
                ->check('#' . $ids[3])
                ->assertSeeIn(self::LIST . ' [data-limited-count]', '3 of 3 programmes chosen');

            // The search box only hides rows; it never changes what is ticked.
            $browser->type(self::LIST . ' input[type=search]', 'zzzz-no-such-programme')
                ->assertSeeIn(self::LIST . ' [data-limited-count]', '3 of 3 programmes chosen')
                ->type(self::LIST . ' input[type=search]', '');

            $browser->press('Save programme')
                ->waitForText('Your programme is saved.', self::WAIT, false);
        });

        $this->assertCount(3, ApplicantProgramme::where('profile_id', $profile->profile_id)->get(), 'all three were saved in the one submission');
    }
}
