<?php

namespace Tests\Browser;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The listing form's behaviour that only exists in a browser: following the level,
 * the stricter-rules fold, a copy of an earlier listing, and a preview in a new tab
 * that must not leave the Save button stuck.
 */
class ListingFormBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    private const LEVEL = '#field-education_level';

    public function test_the_form_follows_the_level_and_clears_what_no_longer_applies(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->provider())
                ->visit('/opportunities/create')
                ->waitFor(self::LEVEL, self::WAIT);

            // Undergraduate uses everything.
            $browser->select(self::LEVEL, EducationLevel::UNDERGRADUATE)
                ->assertVisible('#field-target_field')
                ->type('#field-target_field', 'Engineering')
                ->click('#advanced-rules summary')
                ->waitFor('#field-min_academic_points', self::WAIT)
                ->type('#field-min_academic_points', '12');

            // Form 1 has no field of study and no A-Level points: they go, and their values with them.
            $browser->select(self::LEVEL, EducationLevel::FORM_1)
                ->assertMissing('#field-target_field')
                ->assertMissing('#field-min_academic_points');

            // And they do not come back with the old text when the level is changed back.
            $browser->select(self::LEVEL, EducationLevel::UNDERGRADUATE)
                ->assertVisible('#field-target_field')
                ->assertValue('#field-target_field', '')
                ->assertValue('#field-min_academic_points', '');

            // A PhD keeps a field but has no points and no school subjects.
            $browser->select(self::LEVEL, EducationLevel::PHD)
                ->assertVisible('#field-target_field')
                ->assertMissing('#field-min_academic_points')
                ->assertMissing('#add-subject-requirement');
        });
    }

    public function test_the_stricter_rules_are_folded_away_and_open_on_a_failed_save(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->provider())
                ->visit('/opportunities/create')
                ->waitFor(self::LEVEL, self::WAIT)
                ->assertMissing('#field-max_age');

            $this->assertFalse($browser->script("return document.getElementById('advanced-rules').open;")[0]);

            $browser->click('#advanced-rules summary')->assertVisible('#field-max_age');

            // A rule filled in, a required field left empty: the form comes back with the rule still showing.
            $browser->select(self::LEVEL, EducationLevel::UNDERGRADUATE)
                ->type('#field-max_age', '25')
                ->press('Submit for review')
                ->waitFor('.invalid-feedback', self::WAIT);

            $this->assertTrue($browser->script("return document.getElementById('advanced-rules').open;")[0], 'the rule the provider typed must still be on screen');
            $browser->assertVisible('#field-max_age')->assertValue('#field-max_age', '25');
        });
    }

    public function test_duplicating_opens_a_prefilled_form_with_next_years_title_and_no_deadline(): void
    {
        $source = $this->listing('Browser Bursary 2026');

        $this->browse(function (Browser $browser) use ($source) {
            $browser->loginAs($this->provider())
                ->visit('/opportunities/' . $source->opportunity_id . '/duplicate')
                ->waitFor('#field-title', self::WAIT)
                ->assertValue('#field-title', 'Browser Bursary 2027')
                ->assertValue('#field-deadline', '')
                ->assertValue('#field-target_field', 'Engineering')
                ->assertSee('Copied from');
        });
    }

    public function test_preview_opens_a_new_tab_and_leaves_save_working(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->provider())
                ->visit('/opportunities/create')
                ->waitFor(self::LEVEL, self::WAIT)
                ->type('#field-title', 'Previewed Bursary')
                ->type('#field-description', 'A description to preview.')
                ->press('Preview');

            // The preview is in a second tab.
            $browser->pause(1500);
            $handles = $browser->driver->getWindowHandles();
            $this->assertCount(2, $handles, 'the preview must open in a new tab');

            $browser->driver->switchTo()->window($handles[1]);
            $browser->waitForText('Nothing has been saved', self::WAIT)
                ->assertSee('Previewed Bursary');
            $browser->driver->close();
            $browser->driver->switchTo()->window($handles[0]);

            // Back on the form, Save is not stuck busy and the form is not marked as submitting.
            $this->assertFalse($browser->script("return document.querySelector('form').dataset.szSubmitting === 'true';")[0]);
            $this->assertFalse($browser->script("return document.querySelector('[data-sz-submit]').classList.contains('is-busy');")[0]);
        });

        $this->assertSame(0, Opportunity::where('title', 'Previewed Bursary')->count(), 'a preview saves nothing');
    }

    private function provider(): User
    {
        return User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function listing(string $title): Opportunity
    {
        $provider = $this->provider();

        return Opportunity::create([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => $title,
            'description' => 'A listing for the form browser tests.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDays(5),
            'reviewed_at' => Carbon::now()->subDays(4),
            'reviewed_by' => 'admin@scholarzim.co.zw',
        ]);
    }
}
