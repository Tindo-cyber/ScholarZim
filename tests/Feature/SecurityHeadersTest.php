<?php

namespace Tests\Feature;

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
}
