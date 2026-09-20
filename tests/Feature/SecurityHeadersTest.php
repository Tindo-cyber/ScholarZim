<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The hardening headers carried over from the Spring SecurityConfig. */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_hardening_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_csp_blocks_inline_and_third_party_scripts(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);

        // The views carry no inline <script>, so script-src must not relax.
        $this->assertStringContainsString("script-src 'self';", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    public function test_hsts_is_only_sent_over_tls(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
    }

    /**
     * In production TLS stops at the reverse proxy, so the only evidence the
     * app has that a request arrived over HTTPS is X-Forwarded-Proto. That
     * header is discarded unless a proxy is trusted, which left the deployed
     * site - HTTPS end to end - never sending HSTS at all. config/trustedproxy
     * supplies the missing trust from TRUSTED_PROXIES.
     */
    public function test_hsts_is_sent_when_a_trusted_proxy_reports_tls(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->get('/', ['X-Forwarded-Proto' => 'https'])
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /**
     * The other half of that bargain: the header is only worth reading from a
     * proxy we trust. With none configured - the default everywhere but the
     * production compose file - a client cannot talk the app into believing
     * its plain-HTTP request was secure.
     */
    public function test_a_forwarded_scheme_is_ignored_when_no_proxy_is_trusted(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->get('/', ['X-Forwarded-Proto' => 'https'])
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    /**
     * The assertion the CSP has always been written against, finally made.
     *
     * `script-src 'self'` only works as a policy if the views hold up their end
     * of it, and for a while one of them did not: the subject-requirements grid
     * grew an inline <script>, the browser refused to execute it, and a
     * provider could not attach a required subject to a listing on either form.
     * Nothing failed loudly. The page rendered, the button was there, the
     * button did nothing, and the one console line saying why was buried in CSP
     * noise from the vendor theme's CDN font references.
     *
     * A <script type="application/json"> data block is not executed and is not
     * covered by script-src, so the catalogue those grids read stays exempt -
     * but it has to say so in its type attribute, which is the other half of
     * what this checks.
     *
     * Every authenticated page a provider or an applicant can reach with a
     * dynamic form on it, because the next one of these will not be added to
     * the same view as the last one.
     */
    public function test_no_view_carries_an_executable_inline_script(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $applicant = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $listing = Opportunity::where('provider_user_id', $provider->user_id)->firstOrFail();

        $pages = [
            [$provider, '/opportunities/create'],
            [$provider, '/opportunities/' . $listing->opportunity_id . '/edit'],
            [$applicant, '/applicant/profile'],
        ];

        foreach ($pages as [$user, $url]) {
            // Each page gets a clean session: signing in as a second user while
            // the first one's session is still around trips the session-hash
            // check that suspension relies on, and the request lands on /login.
            $this->flushSession();
            $this->app['auth']->forgetGuards();

            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            preg_match_all('/<script\b[^>]*>/i', $html, $matches);

            foreach ($matches[0] as $tag) {
                $isExternal = str_contains($tag, 'src=');
                $isDataBlock = str_contains($tag, 'type="application/json"');

                $this->assertTrue(
                    $isExternal || $isDataBlock,
                    "{$url} carries an inline <script> that `script-src 'self'` will block: {$tag}"
                );
            }
        }
    }

    /**
     * The development branch of the policy cannot be reached from anywhere but
     * a local machine. The test environment is `testing`, production is
     * `production`, and both take the constant untouched - so the header a
     * deployed instance sends is the strict one whatever is sitting in
     * public/hot.
     */
    public function test_the_policy_is_strict_outside_local(): void
    {
        foreach (['testing', 'production', 'staging'] as $environment) {
            app()['env'] = $environment;

            $csp = $this->get('/')->headers->get('Content-Security-Policy');

            $this->assertSame("script-src 'self'; ", $this->directive($csp, 'script-src'), $environment);
            $this->assertSame("connect-src 'self'; ", $this->directive($csp, 'connect-src'), $environment);
            $this->assertStringNotContainsString('ws://', $csp, $environment);
            $this->assertStringNotContainsString('localhost', $csp, $environment);
        }
    }

    private function directive(string $csp, string $name): string
    {
        preg_match('/' . preg_quote($name, '/') . '[^;]*; /', $csp, $m);

        return $m[0] ?? '';
    }
}
