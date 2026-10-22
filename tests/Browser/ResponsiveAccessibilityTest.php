<?php

namespace Tests\Browser;

use App\Models\Opportunity;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The main pages at phone, tablet and desktop width: nothing scrolls sideways, every page has one main heading and a
 * title, every control and image has a name a screen reader can read, and a refused form says what is wrong in a way
 * assistive technology is told about. Layout and focus are only real in a browser.
 */
class ResponsiveAccessibilityTest extends DuskTestCase
{
    private const SIZES = ['phone' => [375, 800], 'tablet' => [768, 1024], 'desktop' => [1352, 900]];

    /** Lists every problem on the current page, as short strings. */
    private const AUDIT = <<<'JS'
        var problems = [];
        var de = document.documentElement;
        if (de.scrollWidth > de.clientWidth + 1) problems.push('scrolls sideways (' + de.scrollWidth + ' > ' + de.clientWidth + ')');
        if (!de.getAttribute('lang')) problems.push('no lang on <html>');
        if (!document.title.trim()) problems.push('no <title>');
        var h1 = document.querySelectorAll('h1').length;
        if (h1 !== 1) problems.push(h1 + ' <h1> elements');
        function visible(e) { return !e.closest('[hidden]') && e.getClientRects().length > 0 || e.type === 'hidden' ? e.type !== 'hidden' : false; }
        document.querySelectorAll('input, select, textarea').forEach(function (e) {
            if (['hidden', 'submit', 'button', 'image', 'reset'].indexOf(e.type) !== -1 || e.disabled) return;
            var named = (e.labels && e.labels.length) || e.getAttribute('aria-label') || e.getAttribute('aria-labelledby') || e.getAttribute('title');
            if (!named) problems.push('unnamed control ' + (e.name || e.id || e.type) + ' [id=' + e.id + ', in document=' + document.contains(e) + ', readyState=' + document.readyState + ', label for id=' + (e.id ? document.querySelectorAll('label[for="' + e.id + '"]').length : 'n/a') + ', parent=' + (e.parentElement ? e.parentElement.outerHTML.slice(0, 300) : '') + ']');
        });
        document.querySelectorAll('button, a[href]').forEach(function (e) {
            if (e.closest('[hidden]') || !e.getClientRects().length) return;
            var text = (e.textContent || '').trim() || e.getAttribute('aria-label') || e.getAttribute('title') || (e.querySelector('img[alt]') || {}).alt;
            if (!text) problems.push('unnamed ' + e.tagName.toLowerCase() + ' ' + (e.getAttribute('href') || e.className));
        });
        document.querySelectorAll('img').forEach(function (e) {
            if (!e.hasAttribute('alt')) problems.push('image without alt ' + (e.getAttribute('src') || '').slice(0, 40));
        });
        var inspected = document.querySelectorAll('input, select, textarea, button, a[href], img').length;
        return (inspected > 3 ? '' : 'only ' + inspected + ' elements inspected; ') + problems.join(' | ');
    JS;

    protected function capturesNetwork(): bool
    {
        return true;
    }

    private function audit(Browser $browser, string $where): string
    {
        $browser->pause(400);
        $this->noteDocument($browser);

        $problems = (string) $browser->script(self::AUDIT)[0];

        // A page that fails keeps its evidence: the body the network delivered, the page the browser built, the console.
        if ($problems !== '') {
            $problems .= ' [evidence: ' . $this->saveDiagnostics($browser, $where, ['problems: ' . $problems]) . ']';
        }

        return $where . ': ' . $problems;
    }

    private function listingId(): int
    {
        return (int) Opportunity::publiclyVisible()->orderBy('opportunity_id')->value('opportunity_id');
    }

    public function test_public_pages_fit_every_screen_and_are_readable_by_assistive_technology(): void
    {
        $failures = [];

        $this->browse(function (Browser $browser) use (&$failures) {
            foreach (self::SIZES as $name => [$w, $h]) {
                $browser->resize($w, $h);

                foreach (['/', '/scholarships', '/scholarships/' . $this->listingId(), '/login', '/register', '/register/provider', '/forgot-password'] as $path) {
                    $browser->visit($path);
                    $report = $this->audit($browser, "$name $path");

                    if (! str_ends_with($report, ': ')) {
                        $failures[] = $report;
                    }
                }
            }
        });

        $this->assertSame([], $failures);
    }

    public function test_signed_in_pages_fit_every_screen_and_are_readable_by_assistive_technology(): void
    {
        $failures = [];
        $actors = [
            'applicant' => [User::where('email', 'student@scholarzim.co.zw')->firstOrFail(), ['/applicant/dashboard', '/applicant/profile', '/my-applications', '/applicant/recommendations', '/notifications']],
            'provider' => [User::where('email', 'provider@scholarzim.co.zw')->firstOrFail(), ['/provider/dashboard', '/opportunities/create', '/provider/applications', '/provider/analytics']],
            'admin' => [User::where('email', 'admin@scholarzim.co.zw')->firstOrFail(), ['/admin/dashboard', '/admin/users', '/admin/analytics', '/admin/reports']],
        ];

        foreach ($actors as $role => [$user, $paths]) {
            $this->browse(function (Browser $browser) use ($user, $paths, $role, &$failures) {
                $browser->loginAs($user);

                foreach (self::SIZES as $name => [$w, $h]) {
                    $browser->resize($w, $h);

                    foreach ($paths as $path) {
                        $browser->visit($path);
                        $report = $this->audit($browser, "$role $name $path");

                        if (! str_ends_with($report, ': ')) {
                            $failures[] = $report;
                        }
                    }
                }
            });
        }

        $this->assertSame([], $failures);
    }

    public function test_the_first_tab_stop_is_the_skip_link_and_it_reaches_the_content(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs(User::where('email', 'student@scholarzim.co.zw')->firstOrFail())->visit('/applicant/dashboard')->pause(300);
            $browser->script('document.body.focus(); document.activeElement.blur();');
            $browser->driver->getKeyboard()->sendKeys(\Facebook\WebDriver\WebDriverKeys::TAB);

            $first = $browser->script('return document.activeElement.className + "|" + document.activeElement.getAttribute("href");')[0];
            $this->assertStringContainsString('sz-skip-link', $first);
            $this->assertStringContainsString('#sz-main-content', $first);

            $browser->driver->getKeyboard()->sendKeys(\Facebook\WebDriver\WebDriverKeys::ENTER);
            $browser->pause(300);
            $this->assertSame('sz-main-content', $browser->script('return document.activeElement.id || (location.hash && location.hash.slice(1));')[0]);
        });
    }

    public function test_a_refused_form_names_the_problem_and_tells_assistive_technology(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')->waitFor('input[name="email"]', 10);
            $browser->type('email', 'nobody@example.test')->type('password', 'not-the-password')->press('Sign in')
                ->waitForText('Please fix the following:', 10);

            $state = $browser->script(
                "var f = document.querySelector('input[name=email]');"
                . "var summary = document.querySelector('.alert-danger');"
                . "return JSON.stringify({role: summary ? summary.getAttribute('role') : null, invalid: f.getAttribute('aria-invalid'), describedby: f.getAttribute('aria-describedby'), described: !!(f.getAttribute('aria-describedby') && document.getElementById(f.getAttribute('aria-describedby').split(' ')[0]))});"
            )[0];
            $state = json_decode($state, true);

            $this->assertSame('alert', $state['role'], 'the summary is announced');
            $this->assertSame('true', $state['invalid'], 'the field is marked invalid');
            $this->assertTrue($state['described'], 'and points at the message that explains it');
        });
    }
}
