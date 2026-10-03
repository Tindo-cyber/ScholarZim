<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The redesigned public landing page: concise, with the catalogue/ScholarFit
 * explanation/provider details extracted to their own pages rather than all
 * living on the homepage. These checks are the ones a Feature test can't
 * make - that the hero CTAs actually navigate in a real browser, that the
 * page has no console errors, and that it holds together at a phone width.
 */
class HomepageRedesignTest extends DuskTestCase
{
    public function test_the_homepage_shows_the_concise_hero_and_key_sections(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->assertSee('Find scholarships that fit your profile.')
                // .sz-eyebrow is upper-cased by CSS (text-transform), and
                // Dusk's assertSee checks the browser-rendered text, not the
                // source markup - same reason the old homepage's identical
                // "On ScholarZim right now" eyebrow would have failed too.
                ->assertSee('SCHOLARZIM AT A GLANCE')
                ->assertSee('ScholarZim brings the scholarship process together')
                ->assertSee('How ScholarZim works')
                ->assertSee('Meet ScholarFit')
                ->assertSee('Are you an organisation offering scholarships?')
                ->assertDontSee('Closing soon')
                ->assertDontSee('Browse by field of study');
        });
    }

    public function test_the_find_scholarships_cta_navigates_to_the_catalogue(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->clickLink('Find scholarships')
                ->assertRouteIs('scholarships.index');
        });
    }

    public function test_the_how_scholarfit_works_cta_navigates_to_the_dedicated_page(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->clickLink('How ScholarFit works')
                ->assertRouteIs('scholarfit')
                ->assertSee('Eligibility is not an award.')
                ->assertSee('Is ScholarZim free for students?');
        });
    }

    public function test_the_for_providers_ctas_navigate_to_provider_registration(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->clickLink('For providers')
                ->assertRouteIs('register.provider');
        });
    }

    public function test_the_explore_scholarfit_and_learn_more_ctas_both_reach_the_scholarfit_page(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->clickLink('Explore ScholarFit')
                ->assertRouteIs('scholarfit');

            $browser->visit('/')
                ->clickLink('Learn how ScholarFit works')
                ->assertRouteIs('scholarfit');
        });
    }

    /**
     * The simplified top nav: just the brand mark (which is the home link -
     * no separate "Home" text item, since that was a second link to the
     * exact same place) plus sign-in/create-account. Everything else stays
     * reachable through the footer.
     */
    public function test_the_top_nav_is_simplified_but_the_footer_still_reaches_everything(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->within('header.sz-public-nav', function (Browser $nav) {
                    $nav->assertSee('Sign in')
                        ->assertSee('Create free account')
                        ->assertDontSee('Browse scholarships')
                        ->assertDontSee('For providers');
                })
                ->assertAttribute('header.sz-public-nav .navbar-brand', 'href', route('home'))
                ->within('footer.sz-public-footer', function (Browser $footer) {
                    $footer->assertSeeLink('Browse scholarships')
                        ->assertSeeLink('How ScholarFit works')
                        ->assertSeeLink('Register');
                });
        });
    }

    public function test_the_homepage_has_no_horizontal_scroll_on_a_phone_width(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->resize(375, 812)
                ->visit('/')
                ->pause(300);

            $overflow = $browser->script(
                'return document.documentElement.scrollWidth - document.documentElement.clientWidth;'
            )[0];

            $this->assertLessThanOrEqual(1, $overflow, 'the homepage scrolls horizontally at a phone width');
        });
    }

    /**
     * One exception, not a loophole: README's "Known vendor console issues"
     * documents bvite.js throwing this exact dompurify-related TypeError on
     * every page load, from minified vendor code with no clean fix short of
     * replacing the theme. Anything else is this test's actual job to catch.
     */
    public function test_the_homepage_produces_no_new_browser_console_errors(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/');

            $unexpected = collect($browser->driver->manage()->getLog('browser'))
                ->where('level', 'SEVERE')
                ->reject(fn (array $entry) => str_contains($entry['message'], "reading 'call'"));

            $this->assertCount(0, $unexpected, 'unexpected console errors on the homepage: ' . $unexpected->pluck('message')->implode(' | '));
        });
    }
}
