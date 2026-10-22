<?php

namespace Tests\Browser;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\EducationLevel;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The provider's "Who is it open to?" card as a person uses it: the three ways to say how widely it applies, the
 * searchable programme list with its chosen items, the level that narrows it, the age guidance that follows the level, and
 * what is still there after a failed save. The server rules behind them are in ProgrammeTargetingAndAgeTest.
 */
class ProgrammePickerBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    private const LEVEL = '#field-education_level';

    private function provider(): User
    {
        return User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function js(Browser $browser, string $expression): mixed
    {
        return $browser->script('return ' . $expression . ';')[0];
    }

    private function open(Browser $browser, string $level = EducationLevel::UNDERGRADUATE): Browser
    {
        return $browser->loginAs($this->provider())
            ->visit('/opportunities/create')
            ->waitFor(self::LEVEL, self::WAIT)
            ->select(self::LEVEL, $level);
    }

    private function visible(Browser $browser, string $selector): bool
    {
        return (bool) $this->js($browser, "(function(){var e=document.querySelector('" . $selector . "');return !!e && !e.hidden && e.offsetParent !== null;})()");
    }

    public function test_the_three_ways_to_say_who_it_is_open_to_show_only_their_own_list(): void
    {
        $this->browse(function (Browser $browser) {
            $this->open($browser);

            $browser->script("document.getElementById('open-to-programmes').click();");
            $this->assertTrue($this->visible($browser, '#scope-programmes'), 'specific programmes: the programme list');
            $this->assertFalse($this->visible($browser, '#scope-fields'));

            $browser->script("document.getElementById('open-to-fields').click();");
            $this->assertTrue($this->visible($browser, '#scope-fields'), 'an entire field: the field list');
            $this->assertFalse($this->visible($browser, '#scope-programmes'));

            $browser->script("document.getElementById('open-to-all').click();");
            $this->assertFalse($this->visible($browser, '#scope-fields'));
            $this->assertFalse($this->visible($browser, '#scope-programmes'), 'all programmes: neither');
        });
    }

    public function test_programmes_can_be_searched_chosen_and_removed_and_show_their_field(): void
    {
        $this->browse(function (Browser $browser) {
            $this->open($browser);
            $browser->script("document.getElementById('open-to-programmes').click();");

            $browser->waitFor('#scope-programmes [data-picker-search]', self::WAIT)
                ->type('#scope-programmes [data-picker-search]', 'Computer Science');

            $shown = $this->js($browser, "document.querySelectorAll('#scope-programmes [data-picker-option]:not([hidden])').length");
            $this->assertGreaterThan(0, $shown, 'the search finds programmes');
            $this->assertLessThan(60, $shown, 'and narrows the list');

            $heading = $this->js($browser, "document.querySelector('#scope-programmes [data-picker-group]:not([hidden]) .fw-semibold').textContent");
            $this->assertStringContainsString('Undergraduate', $heading, 'grouped by the level chosen above');
            $this->assertStringContainsString(' · ', $heading, 'and by the field the catalogue files them under');

            $browser->script("document.querySelector('#scope-programmes [data-picker-option]:not([hidden]) input').click();");

            $this->assertSame('1', $this->js($browser, "document.querySelector('#scope-programmes [data-picker-count]').textContent"));
            $chip = $this->js($browser, "document.querySelector('#scope-programmes [data-picker-chips] button').textContent");
            $this->assertStringContainsString('(', $chip, 'the chosen item names its field');

            $browser->script("document.querySelector('#scope-programmes [data-picker-chips] button').click();");

            $this->assertSame('0', $this->js($browser, "document.querySelector('#scope-programmes [data-picker-count]').textContent"));
            $this->assertSame(0, $this->js($browser, "document.querySelectorAll('#scope-programmes input:checked').length"), 'removing the chip unticks the box');
        });
    }

    public function test_changing_the_level_narrows_the_programmes_and_says_what_it_removed(): void
    {
        $this->browse(function (Browser $browser) {
            $this->open($browser);
            $browser->script("document.getElementById('open-to-programmes').click();");

            $browser->waitFor('#scope-programmes [data-picker-search]', self::WAIT);
            $browser->script("document.querySelector('#scope-programmes [data-picker-option][data-level=\"UNDERGRADUATE\"] input').click();");
            $this->assertSame('1', $this->js($browser, "document.querySelector('#scope-programmes [data-picker-count]').textContent"));

            $browser->select(self::LEVEL, EducationLevel::DIPLOMA);

            $this->assertSame(0, $this->js($browser, "document.querySelectorAll('#scope-programmes [data-picker-option][data-level=\"UNDERGRADUATE\"]:not([hidden])').length"), 'other levels are hidden');
            $this->assertSame(0, $this->js($browser, "document.querySelectorAll('#scope-programmes input:checked').length"), 'a programme at another level is unticked');
            $this->assertTrue($this->visible($browser, '#scope-programmes [data-picker-note]'), 'and the person is told');
        });
    }

    public function test_the_missing_programme_option_is_there_beside_the_list(): void
    {
        $this->browse(function (Browser $browser) {
            $this->open($browser);
            $browser->script("document.getElementById('open-to-programmes').click();");

            $this->assertTrue($this->visible($browser, '[data-open-programmes] details summary'));
            $this->assertStringContainsString("My programme isn't listed", $this->js($browser, "document.querySelector('[data-open-programmes] details summary').textContent"));
        });
    }

    public function test_the_card_fits_a_phone_screen_and_its_controls_have_names(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->resize(375, 800);
            $this->open($browser);
            $browser->script("document.getElementById('open-to-programmes').click();");
            $browser->waitFor('#scope-programmes [data-picker-search]', self::WAIT);

            $this->assertLessThanOrEqual(
                $this->js($browser, 'document.documentElement.clientWidth'),
                $this->js($browser, 'document.documentElement.scrollWidth'),
                'the page does not scroll sideways at phone width'
            );

            $unnamed = $this->js($browser, "Array.from(document.querySelectorAll('#scope-card input, #scope-card select')).filter(function(e){return e.type !== 'hidden' && !e.labels.length && !e.getAttribute('aria-label');}).length");
            $this->assertSame(0, $unnamed, 'every control in the card has a label');

            $browser->resize(1352, 900);
        });
    }

    public function test_the_age_guidance_follows_the_level(): void
    {
        $this->browse(function (Browser $browser) {
            $this->open($browser, EducationLevel::UNDERGRADUATE);

            $this->assertSame('14', $this->js($browser, "document.getElementById('field-max_age').min"));
            $browser->select(self::LEVEL, EducationLevel::POSTGRADUATE);
            $this->assertSame('18', $this->js($browser, "document.getElementById('field-max_age').min"));
            $this->assertStringStartsWith('At least 18', $this->js($browser, "document.getElementById('field-max_age-hint').textContent.trim()"));
            $browser->select(self::LEVEL, EducationLevel::FORM_1);
            $this->assertSame('12', $this->js($browser, "document.getElementById('field-max_age').min"));
        });
    }

    public function test_choices_and_the_programme_answer_survive_a_failed_save(): void
    {
        $title = 'Picker Failure ' . uniqid();

        $this->browse(function (Browser $browser) use ($title) {
            $this->open($browser);
            $browser->type('#field-title', $title)
                ->type('#field-description', 'Covers tuition fees.');

            $browser->script("document.getElementById('open-to-programmes').click(); document.getElementById('institutions-any').click();");
            $browser->waitFor('#scope-programmes [data-picker-search]', self::WAIT)
                ->type('#scope-programmes [data-picker-search]', 'Computer Science');
            $browser->script("document.querySelector('#scope-programmes [data-picker-option]:not([hidden]) input').click();");
            $chosen = $this->js($browser, "document.querySelector('#scope-programmes input:checked').value");

            // An age no applicant could meet: refused by the server with the message for it.
            $browser->script("document.getElementById('advanced-rules').open = true;");
            $browser->type('#field-max_age', '13');
            $browser->script("document.getElementById('field-max_age').removeAttribute('min'); document.getElementById('field-max_age').form.noValidate = true;");
            // The seeded provider is trusted, so the button reads "Publish"; an untrusted one's reads "Submit for review".
            $browser->click('#submit-listing')
                ->waitForText('The maximum age must be at least 14 for Undergraduate awards', self::WAIT);

            $this->assertSame($chosen, $this->js($browser, "document.querySelector('#scope-programmes input:checked').value"), 'the ticked programme is still ticked');
            $this->assertTrue($this->js($browser, "document.getElementById('open-to-programmes').checked"), 'and the way it applies is still chosen');
            $this->assertTrue($this->visible($browser, '#scope-programmes'));
            $this->assertSame('13', $this->js($browser, "document.getElementById('field-max_age').value"), 'what was typed is kept, not discarded');
        });

        $this->assertSame(0, Opportunity::where('title', $title)->where('moderation_status', '!=', 'DRAFT')->count(), 'nothing invalid was published');
    }

    // ------------------------------------------------- the list is loaded for the level in use --

    private function liveBoxes(Browser $browser): int
    {
        return (int) $this->js($browser, "document.querySelectorAll('#scope-programmes [data-picker-list] input[type=checkbox]').length");
    }

    private function atLevel(string $level): int
    {
        return \App\Models\Programme::inCatalogue()->where('education_level', $level)->count();
    }

    public function test_no_programme_is_listed_until_a_level_is_chosen_and_then_only_that_levels(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->provider())->visit('/opportunities/create')->waitFor(self::LEVEL, self::WAIT);
            $browser->script("document.getElementById('open-to-programmes').click();");
            $browser->waitFor('#scope-programmes [data-picker-search]', self::WAIT);

            $this->assertSame(0, $this->liveBoxes($browser), 'nothing is rendered, tabbed through or posted before a level is chosen');
            $this->assertTrue($this->visible($browser, '#scope-programmes [data-picker-prompt]'), 'and the person is told what to do');

            $browser->select(self::LEVEL, EducationLevel::UNDERGRADUATE);
            $this->assertSame($this->atLevel(EducationLevel::UNDERGRADUATE), $this->liveBoxes($browser));
            $this->assertSame(0, $this->js($browser, "document.querySelectorAll('#scope-programmes [data-picker-list] [data-picker-option]:not([data-level=\"UNDERGRADUATE\"])').length"));
            $this->assertFalse($this->visible($browser, '#scope-programmes [data-picker-prompt]'));

            $browser->select(self::LEVEL, EducationLevel::DIPLOMA);
            $this->assertSame($this->atLevel(EducationLevel::DIPLOMA), $this->liveBoxes($browser), 'the list follows the level');
            $this->assertSame(0, $this->js($browser, "document.querySelectorAll('#scope-programmes [data-picker-list] [data-picker-option][data-level=\"UNDERGRADUATE\"]').length"));
        });
    }

    public function test_searching_with_no_level_chosen_looks_across_every_level_and_clearing_puts_it_away(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->provider())->visit('/opportunities/create')->waitFor(self::LEVEL, self::WAIT);
            $browser->script("document.getElementById('open-to-programmes').click();");
            $browser->waitFor('#scope-programmes [data-picker-search]', self::WAIT)->type('#scope-programmes [data-picker-search]', 'engineering');

            $levels = $this->js($browser, "Array.from(new Set(Array.from(document.querySelectorAll('#scope-programmes [data-picker-list] [data-picker-option]:not([hidden])')).map(function (e) { return e.getAttribute('data-level'); }))).length");
            $this->assertGreaterThan(1, $levels, 'matches come from more than one level');

            $browser->script("var s = document.querySelector('#scope-programmes [data-picker-search]'); s.value = ''; s.dispatchEvent(new Event('input', {bubbles: true}));");
            $this->assertSame(0, $this->liveBoxes($browser), 'cleared: the list is put away again');
        });
    }

    public function test_going_back_to_a_level_restores_its_programmes_in_order_unticked(): void
    {
        $this->browse(function (Browser $browser) {
            $this->open($browser);
            $browser->script("document.getElementById('open-to-programmes').click();");
            $browser->waitFor('#scope-programmes [data-picker-search]', self::WAIT);
            $before = $this->js($browser, "Array.from(document.querySelectorAll('#scope-programmes [data-picker-group]')).map(function (g) { return g.getAttribute('data-order'); }).join(',')");
            $browser->script("document.querySelector('#scope-programmes [data-picker-option] input').click();");

            $browser->select(self::LEVEL, EducationLevel::DIPLOMA)->select(self::LEVEL, EducationLevel::UNDERGRADUATE);

            $after = $this->js($browser, "Array.from(document.querySelectorAll('#scope-programmes [data-picker-group]')).map(function (g) { return g.getAttribute('data-order'); }).join(',')");
            $this->assertSame($before, $after, 'the groups come back in their original order');
            $this->assertSame(0, $this->js($browser, "document.querySelectorAll('#scope-programmes input:checked').length"), 'a choice at another level was removed, not kept');
        });
    }

    public function test_an_edited_listing_shows_its_saved_programme_ticked_and_still_saves_it(): void
    {
        $programme = \App\Models\Programme::inCatalogue()->where('education_level', EducationLevel::UNDERGRADUATE)->orderBy('id')->firstOrFail();
        $provider = $this->provider();
        $listing = Opportunity::create([
            'provider_user_id' => $provider->user_id, 'provider_name' => $provider->full_name, 'title' => 'Picker Edit Award ' . uniqid(),
            'description' => 'A fixture.', 'education_level' => EducationLevel::UNDERGRADUATE, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'deadline' => '2030-12-31', 'open_to_all_programmes' => false,
            'any_institution' => true, 'status' => \App\Support\OpportunityStatus::ACTIVE,
            'moderation_status' => \App\Support\OpportunityModerationStatus::APPROVED, 'submitted_at' => now()->subDay(),
        ]);
        \App\Models\OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'programme_id' => $programme->id]);

        try {
            $this->browse(function (Browser $browser) use ($listing, $programme, $provider) {
                $browser->loginAs($provider)->visit('/opportunities/' . $listing->opportunity_id . '/edit')->waitFor(self::LEVEL, self::WAIT)->pause(300);

                $this->assertSame($this->atLevel(EducationLevel::UNDERGRADUATE), $this->liveBoxes($browser));
                $this->assertSame('1', $this->js($browser, "document.querySelector('#scope-programmes [data-picker-count]').textContent"));
                $this->assertTrue((bool) $this->js($browser, "document.getElementById('scope-programmes-" . $programme->id . "').checked"));
            });
        } finally {
            \App\Models\OpportunityScope::where('opportunity_id', $listing->opportunity_id)->delete();
            $listing->delete();
        }
    }
}
