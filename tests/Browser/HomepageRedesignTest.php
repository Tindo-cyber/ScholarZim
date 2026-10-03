<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The photo-led public landing page, built to the agreed visual reference
 * (hero photo + overlay nav, three feature cards, a ScholarFit panel, large
 * student/provider panels, a photo-backed closing CTA) in the existing
 * ScholarZim palette with a gold accent. The landing page introduces the
 * platform rather than reproducing it: the full "how it works" process and
 * the scholarship catalogue each live on their own page, which these tests
 * also cover. The rest is what a Feature test can't check - that the CTAs
 * actually navigate in a real browser, that the overlay nav and its mobile
 * menu both work, that the page has no console errors, and that it holds
 * together at a phone width.
 */
class HomepageRedesignTest extends DuskTestCase
{
    public function test_the_homepage_shows_the_hero_and_key_sections(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->assertSee('Discover Opportunities.')
                ->assertSee('Build Your Future.')
                ->assertSee('ScholarFit helps you find opportunities that fit your profile.')
                ->assertSee('Your next opportunity could start here.')
                ->assertSee('Reach students through a structured scholarship platform.')
                ->assertSee('Ready to discover your next opportunity?')
                // The full three-step process is its own page now - see
                // test_the_how_it_works_page_shows_the_three_steps - so the
                // landing page introduces it instead of reproducing it.
                ->assertDontSee('Create Your Profile')
                ->assertDontSee('Apply & Track')
                // No stats card on this design, and no scoring language.
                ->assertDontSee('ScholarFit score')
                ->assertDontSee('match percentage');
        });
    }

    public function test_the_hero_find_scholarships_cta_navigates_to_the_catalogue(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->clickLink('Find Scholarships')
                ->assertRouteIs('scholarships.index');
        });
    }

    public function test_the_hero_learn_how_it_works_cta_navigates_to_the_how_it_works_page(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->clickLink('Learn How It Works')
                ->assertRouteIs('how-it-works')
                ->assertSee('Create Your Profile')
                ->assertSee('Apply & Track');
        });
    }

    public function test_the_how_it_works_page_shows_the_three_steps(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/how-it-works')
                ->assertSee('How ScholarZim Works')
                ->assertSee('Create Your Profile')
                ->assertSee('Discover Opportunities')
                ->assertSee('Apply & Track')
                ->clickLink('Find Scholarships')
                ->assertRouteIs('scholarships.index');
        });
    }

    public function test_the_student_panel_cta_navigates_to_registration(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->clickLink('Get Started')
                ->assertRouteIs('register');
        });
    }

    public function test_the_provider_panel_cta_navigates_to_provider_registration(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->clickLink('Learn More')
                ->assertRouteIs('register.provider');
        });
    }

    public function test_the_closing_cta_navigates_to_the_catalogue(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->clickLink('Explore Scholarships')
                ->assertRouteIs('scholarships.index');
        });
    }

    /**
     * The real routes this page's nav uses - Home, Find Scholarships, How
     * It Works (the dedicated process page, not the ScholarFit explanation
     * page), Sign In, Get Started. "About" is deliberately absent: no such
     * page exists, and every nav item must be a route that actually
     * exists, not one invented to match a reference.
     */
    public function test_the_nav_links_to_real_routes_only(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->within('header.sz-public-nav', function (Browser $nav) {
                    $nav->assertSeeLink('Home')
                        ->assertSeeLink('Find Scholarships')
                        ->assertSeeLink('How It Works')
                        ->assertSee('Sign in')
                        ->assertSee('Get Started')
                        ->assertDontSee('About');
                });
        });
    }

    /**
     * One header system: the theme toggle and the install-app control
     * (JS-revealed only when the browser actually offers it - see
     * install-app.blade.php) are rendered unconditionally on every public
     * page, landing included. Only their class changes between the
     * overlay nav and the ordinary solid one, not their presence.
     */
    public function test_the_theme_toggle_and_install_control_render_on_every_public_page(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->within('header.sz-public-nav', function (Browser $nav) {
                    $nav->assertPresent('#bd-theme')
                        ->assertPresent('[data-pwa-install]');
                });

            $browser->visit(route('how-it-works'))
                ->within('header.sz-public-nav', function (Browser $nav) {
                    $nav->assertPresent('#bd-theme')
                        ->assertPresent('[data-pwa-install]');
                });
        });
    }

    /** Switching theme from the landing page's overlay nav persists across navigation and a refresh. */
    public function test_the_theme_toggle_persists_the_chosen_theme(): void
    {
        $this->browse(function (Browser $browser) {
            $readTheme = fn (Browser $b) => $b->script(
                "return document.documentElement.getAttribute('data-bs-theme');"
            )[0];

            $browser->visit('/')
                ->click('#bd-theme')
                ->pause(150)
                ->click('[data-bs-theme-value="dark"]')
                ->pause(150);

            $this->assertSame('dark', $readTheme($browser));

            $browser->visit(route('how-it-works'));
            $this->assertSame('dark', $readTheme($browser), 'theme should persist across navigation');

            $browser->refresh();
            $this->assertSame('dark', $readTheme($browser), 'theme should persist after a refresh');
        });
    }

    /** Get Started uses the same primary-button colour as every other page - no landing-only gold variant. */
    public function test_the_landing_get_started_button_matches_the_sitewide_primary_colour(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')->pause(200);

            $landing = $browser->script(
                "return getComputedStyle(document.querySelector('header.sz-public-nav .btn-primary')).backgroundColor;"
            )[0];

            $browser->visit(route('how-it-works'))->pause(200);

            $elsewhere = $browser->script(
                "return getComputedStyle(document.querySelector('header.sz-public-nav .btn-primary')).backgroundColor;"
            )[0];

            $this->assertSame($elsewhere, $landing, 'Get Started should be the same colour on the landing page as everywhere else');
        });
    }

    /** The footer still reaches provider sign-in/registration, which the top nav does not surface directly. */
    public function test_the_footer_reaches_provider_routes(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->within('footer.sz-public-footer', function (Browser $footer) {
                    $footer->assertSeeLink('Register')
                        ->assertSeeLink('Provider sign in');
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

    /** The overlay nav's mobile menu gets its own solid background - see the CSS comment on .navbar-collapse.show. */
    public function test_the_mobile_nav_menu_opens_and_is_readable(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->resize(375, 812)
                ->visit('/')
                ->click('.navbar-toggler')
                ->pause(300)
                ->assertSee('Find Scholarships')
                ->assertSee('How It Works');
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
