<?php

namespace Tests\Browser;

use App\Models\ApplicantProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * An applicant under 18 cannot save the profile without guardian details, and is told on the page where to add them.
 */
class GuardianRequiredBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        $profile = ApplicantProfile::where('user_id', $this->student()->user_id)->firstOrFail();
        $this->original = $profile->only(['date_of_birth', 'institution_name', 'guardian_name', 'guardian_phone', 'guardian_relationship', 'guardian_confirmed_at']);
    }

    protected function tearDown(): void
    {
        ApplicantProfile::where('user_id', $this->student()->user_id)->update($this->original);

        parent::tearDown();
    }

    private function student(): User
    {
        return User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    private function setBirthday(Browser $browser, Carbon $date): void
    {
        $browser->script("var d = document.querySelector('[name=date_of_birth]'); d.value = '" . $date->toDateString() . "';"
            . "d.dispatchEvent(new Event('input', {bubbles: true})); d.dispatchEvent(new Event('change', {bubbles: true}));");
    }

    public function test_the_guardian_section_follows_the_date_of_birth_and_is_required_while_shown(): void
    {
        ApplicantProfile::where('user_id', $this->student()->user_id)->update(['guardian_name' => null, 'guardian_phone' => null, 'guardian_relationship' => null, 'guardian_confirmed_at' => null]);

        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->student())->visit('/applicant/profile')->waitFor('#field-biography', self::WAIT);

            // An adult: the section is hidden and nothing in it is required.
            $this->setBirthday($browser, Carbon::today()->subYears(25));
            $browser->assertMissing('#sz-guardian-card');
            $this->assertFalse($browser->script("return document.querySelector('[name=guardian_name]').required;")[0]);

            // Under 18: the section appears and its details are required.
            $this->setBirthday($browser, Carbon::today()->subYears(15));
            $browser->assertVisible('#sz-guardian-card');
            foreach (['guardian_name', 'guardian_phone', 'guardian_relationship'] as $field) {
                $this->assertTrue($browser->script("return document.querySelector('[name=$field]').required;")[0], "$field is required while shown");
            }
            $this->assertTrue($browser->script("return document.querySelector('input[type=checkbox][name=guardian_confirmed]').required;")[0]);

            // Back to an adult: no longer required, so nothing invisible can stop a save.
            $this->setBirthday($browser, Carbon::today()->subYears(25));
            $this->assertFalse($browser->script("return document.querySelector('[name=guardian_name]').required;")[0]);
        });
    }

    public function test_saving_without_the_details_shows_the_errors_beside_the_fields_and_keeps_what_was_typed(): void
    {
        ApplicantProfile::where('user_id', $this->student()->user_id)->update(['guardian_name' => null, 'guardian_phone' => null, 'guardian_relationship' => null, 'guardian_confirmed_at' => null]);

        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->student())->visit('/applicant/profile')->waitFor('#field-biography', self::WAIT);

            $this->setBirthday($browser, Carbon::today()->subYears(15));
            $browser->type('institution_name', 'Typed Before Saving')
                ->click('#sz-profile-form button[type=submit]')
                ->waitForText("Enter your parent or guardian's full name", self::WAIT)
                ->assertVisible('#sz-guardian-card')
                ->assertSee("Enter your parent or guardian's phone number")
                ->assertSee('Say how your parent or guardian is related to you')
                ->assertSee('Tick the box to confirm a parent or guardian is aware')
                ->assertDontSee('Profile saved')
                ->assertInputValue('institution_name', 'Typed Before Saving');

            // Giving the details saves it.
            $browser->type('guardian_name', 'Grace Moyo')->type('guardian_phone', '0771234567')->type('guardian_relationship', 'Mother')
                ->check('guardian_confirmed')
                ->click('#sz-profile-form button[type=submit]')
                ->waitForText('Profile saved', self::WAIT);
        });

        $saved = ApplicantProfile::where('user_id', $this->student()->user_id)->firstOrFail();
        $this->assertSame('Grace Moyo', $saved->guardian_name);
        $this->assertSame('Typed Before Saving', $saved->institution_name);
        $this->assertNotNull($saved->guardian_confirmed_at);
    }
}
