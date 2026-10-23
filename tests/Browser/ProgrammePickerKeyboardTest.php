<?php

namespace Tests\Browser;

use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\User;
use App\Support\Deadlines;
use App\Support\EducationLevel;
use Facebook\WebDriver\WebDriverKeys;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The programme picker used with the keyboard alone: real key presses (Tab, Shift+Tab, Space, Enter, arrows and typing)
 * sent to whatever has focus, with no mouse. Only the first focus of a field is placed by script, standing in for tabbing
 * through the long form above it.
 */
class ProgrammePickerKeyboardTest extends DuskTestCase
{
    private const WAIT = 20;

    private const PICKER = '#scope-programmes';

    private function provider(): User
    {
        return User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function js(Browser $browser, string $expression): mixed
    {
        return $browser->script('return ' . $expression . ';')[0];
    }

    private function focus(Browser $browser, string $selector): void
    {
        $browser->script("document.querySelector('" . $selector . "').focus();");
    }

    private function press(Browser $browser, string|array $keys): void
    {
        $browser->driver->getKeyboard()->sendKeys($keys);
    }

    private function active(Browser $browser): string
    {
        return (string) $this->js($browser, "(function(){var e=document.activeElement;return e.tagName.toLowerCase()+(e.type?'['+e.type+']':'')+(e.name?'['+e.name+']':'');})()");
    }

    private function insidePicker(Browser $browser): bool
    {
        return (bool) $this->js($browser, "!!document.activeElement.closest('" . self::PICKER . "')");
    }

    private function open(Browser $browser): void
    {
        $browser->loginAs($this->provider())->visit('/opportunities/create')->waitFor('#field-education_level', self::WAIT)->pause(300);
        $this->focus($browser, '#field-education_level');
        $this->press($browser, 'Undergraduate');
        $this->assertSame('UNDERGRADUATE', $this->js($browser, "document.getElementById('field-education_level').value"), 'typing the level name chooses it');

        // Arrow keys move through a radio group and choose as they go.
        $this->focus($browser, '#open-to-all');
        $this->press($browser, WebDriverKeys::ARROW_DOWN);
        $this->assertTrue((bool) $this->js($browser, "document.getElementById('open-to-programmes').checked"), 'the arrow key chose "specific programmes"');
        $browser->waitFor(self::PICKER . ' [data-picker-search]', self::WAIT);
    }

    public function test_the_programme_list_can_be_searched_ticked_and_cleared_with_the_keyboard(): void
    {
        $this->browse(function (Browser $browser) {
            $this->open($browser);

            $this->focus($browser, self::PICKER . ' [data-picker-search]');
            $this->press($browser, 'computer science');
            $shown = (int) $this->js($browser, "document.querySelectorAll('" . self::PICKER . " [data-picker-option]:not([hidden])').length");
            $this->assertGreaterThan(0, $shown);
            $this->assertLessThan(60, $shown, 'typing narrows the list');

            // Tab goes from the search box straight to the first match.
            $this->press($browser, WebDriverKeys::TAB);
            $this->assertSame('input[checkbox][scope_programmes[]]', $this->active($browser));
            $this->press($browser, WebDriverKeys::SPACE);
            $this->assertSame('1', $this->js($browser, "document.querySelector('" . self::PICKER . " [data-picker-count]').textContent"), 'Space ticks it and it is listed as chosen');
            $this->assertStringContainsString('Remove', (string) $this->js($browser, "document.querySelector('" . self::PICKER . " [data-picker-chips] button').getAttribute('aria-label')"), 'its remove button has a spoken name');

            // Back (Shift+Tab twice) to the chosen item's remove button, and Enter removes it.
            $this->press($browser, [WebDriverKeys::SHIFT, WebDriverKeys::TAB]);
            $this->press($browser, [WebDriverKeys::SHIFT, WebDriverKeys::TAB]);
            $this->assertSame('button[button]', $this->active($browser), 'Shift+Tab reaches the remove button');
            $this->press($browser, WebDriverKeys::ENTER);
            $this->assertSame(0, $this->js($browser, "document.querySelectorAll('" . self::PICKER . " input:checked').length"), 'Enter removes the choice');
        });
    }

    public function test_every_tab_stop_in_the_list_is_one_visible_programme_and_the_search_shortens_it(): void
    {
        $this->browse(function (Browser $browser) {
            $this->open($browser);

            $visible = (int) $this->js($browser, "Array.from(document.querySelectorAll('" . self::PICKER . " input[type=checkbox]')).filter(function (e) { return e.offsetParent !== null; }).length");
            $this->assertGreaterThan(100, $visible, 'the Undergraduate list is long');

            // Walk the list the way a keyboard user must: one Tab at a time from the search box until focus leaves the list.
            $this->focus($browser, self::PICKER . ' [data-picker-search]');
            $stops = 0;
            $seen = [];

            for ($i = 0; $i < $visible + 40 && true; $i++) {
                $this->press($browser, WebDriverKeys::TAB);

                if (! $this->insidePicker($browser)) {
                    break;
                }

                $id = $this->js($browser, 'document.activeElement.id');
                $seen[$id] = true;
                $stops++;
            }

            fwrite(STDERR, "\nKEYBOARD: {$stops} tab stops to cross the Undergraduate programme list ({$visible} visible programmes)\n");

            $this->assertSame($visible, count($seen), 'each stop is a different, visible programme: no hidden, repeated or template stops');
            $this->assertLessThanOrEqual($visible + 1, $stops);

            // Searching first is the way round it.
            $this->focus($browser, self::PICKER . ' [data-picker-search]');
            $this->press($browser, 'accounting');
            $short = (int) $this->js($browser, "Array.from(document.querySelectorAll('" . self::PICKER . " input[type=checkbox]')).filter(function (e) { return e.offsetParent !== null; }).length");
            $this->assertLessThan(30, $short, 'after a search the same walk is short');
        });
    }

    public function test_a_scholarship_can_be_completed_validated_and_submitted_with_the_keyboard(): void
    {
        $title = 'Keyboard Award ' . uniqid();

        try {
            $this->browse(function (Browser $browser) use ($title) {
                $this->open($browser);

                $this->focus($browser, '#field-title');
                $this->press($browser, $title);
                $this->focus($browser, '#field-description');
                $this->press($browser, 'A scholarship completed with the keyboard alone.');
                $this->focus($browser, '#field-funding_type');
                $this->press($browser, 'Full');

                $date = Deadlines::earliest()->addDays(20);
                $this->focus($browser, '#field-deadline');
                $this->press($browser, $date->format('mdY'));

                $this->focus($browser, '#institutions-any');
                $this->press($browser, WebDriverKeys::SPACE);

                // Submitting with nothing chosen is refused, and says what is missing in words.
                $this->focus($browser, 'button[type=submit].btn-lg');
                $this->press($browser, WebDriverKeys::ENTER);
                $browser->waitForText('Choose at least one programme', self::WAIT);
                $this->assertSame(0, Opportunity::where('title', $title)->count(), 'nothing was saved');
                $this->assertTrue((bool) $this->js($browser, "document.querySelector('.alert-danger, .invalid-feedback') !== null"), 'the problem is on the page as an alert or message');

                // Choose a programme by keyboard and submit again.
                $this->focus($browser, self::PICKER . ' [data-picker-search]');
                $this->press($browser, 'computer science');
                $this->press($browser, WebDriverKeys::TAB);
                $this->press($browser, WebDriverKeys::SPACE);
                $this->focus($browser, 'button[type=submit].btn-lg');
                $this->press($browser, WebDriverKeys::ENTER);
                $browser->waitForLocation('/provider/dashboard', self::WAIT);
            });

            $listing = Opportunity::where('title', $title)->firstOrFail();
            $scopes = OpportunityScope::where('opportunity_id', $listing->opportunity_id)->get();

            $this->assertSame(1, $scopes->count());
            $this->assertNotNull($scopes->first()->programme_id, 'the programme ticked with Space was saved');
            $this->assertNull($scopes->first()->field_id, 'and it did not become its whole field');
            $this->assertFalse((bool) $listing->open_to_all_programmes);
        } finally {
            if ($listing = Opportunity::where('title', $title)->first()) {
                OpportunityScope::where('opportunity_id', $listing->opportunity_id)->delete();
                $listing->delete();
            }
        }
    }
}
