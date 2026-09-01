<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Pwa;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ScholarZim as an installable app.
 *
 * Two things are being protected here. The first is that the install works at
 * all: a browser will not offer to install a site whose manifest is missing a
 * field or whose icons 404, and it says so nowhere a developer will see - the
 * button simply never appears.
 *
 * The second matters more. The service worker is the one part of the platform
 * that keeps data on a device after the response has been rendered, and a phone
 * is the most-shared computer any of these students owns. So the tests below
 * assert what is *not* cached as carefully as what is: no page, no session, no
 * document, nothing that a signed-out visitor could not already fetch.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- manifest --

    public function test_the_manifest_is_public_and_correctly_typed(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();

        // Not application/json: Chrome accepts either, but a manifest served as
        // plain JSON is the sort of thing that works in one browser and quietly
        // does not in the next.
        $this->assertStringStartsWith('application/manifest+json', (string) $response->headers->get('content-type'));
    }

    public function test_the_manifest_carries_every_field_an_install_needs(): void
    {
        $manifest = $this->get('/manifest.webmanifest')->json();

        $this->assertSame('ScholarZim', $manifest['name']);
        $this->assertSame('ScholarZim', $manifest['short_name']);
        $this->assertNotEmpty($manifest['description']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('portrait-primary', $manifest['orientation']);
        $this->assertSame(Pwa::THEME_COLOR, $manifest['theme_color']);
        $this->assertSame(Pwa::BACKGROUND_COLOR, $manifest['background_color']);

        // The installed window opens at the root and is routed by role from
        // there, exactly as a bookmark would be.
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
    }

    /**
     * Chromium requires a 192px and a 512px icon before it will offer an
     * install, and a maskable one to avoid framing the mark in a white box on
     * Android.
     */
    public function test_the_manifest_declares_the_icon_sizes_an_install_requires(): void
    {
        $icons = $this->get('/manifest.webmanifest')->json('icons');

        $sizes = array_column($icons, 'sizes');

        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertContains('maskable', array_column($icons, 'purpose'));

        foreach ($icons as $icon) {
            $this->assertSame('image/png', $icon['type']);
        }
    }

    public function test_every_icon_the_manifest_names_is_actually_on_disk(): void
    {
        foreach ($this->get('/manifest.webmanifest')->json('icons') as $icon) {
            $path = public_path(ltrim((string) parse_url($icon['src'], PHP_URL_PATH), '/'));

            $this->assertFileExists($path, "the manifest names an icon that is not there: {$icon['src']}");

            [$width, $height] = getimagesize($path);
            [$declaredWidth, $declaredHeight] = array_map('intval', explode('x', $icon['sizes']));

            $this->assertSame([$declaredWidth, $declaredHeight], [$width, $height], "{$icon['src']} is not the size it claims");
        }
    }

    /** iOS ignores the manifest icons entirely and reads this one from the head. */
    public function test_the_apple_touch_icon_exists(): void
    {
        $this->assertFileExists(public_path('assets/img/apple-touch-icon.png'));
    }

    // ------------------------------------------------------- service worker --

    public function test_the_service_worker_is_served_as_javascript_from_the_site_root(): void
    {
        $response = $this->get('/service-worker.js');

        $response->assertOk();
        $this->assertStringStartsWith('text/javascript', (string) $response->headers->get('content-type'));

        // A worker only controls the path it was served from and below, so the
        // root path is what makes it see navigations at all.
        $this->assertSame('/', $response->headers->get('Service-Worker-Allowed'));
    }

    public function test_the_service_worker_arrives_with_its_version_and_precache_list(): void
    {
        $body = $this->get('/service-worker.js')->getContent();

        $this->assertStringContainsString('const SZ_VERSION = "' . Pwa::cacheVersion() . '"', $body);
        $this->assertStringContainsString('const SZ_PRECACHE = [', $body);
        $this->assertStringContainsString("addEventListener('fetch'", $body);
        $this->assertStringContainsString("addEventListener('install'", $body);
        $this->assertStringContainsString("addEventListener('activate'", $body);
    }

    /**
     * The version has to move when a build does, or an installed app keeps
     * serving the previous deploy's stylesheet with nothing anywhere to say why.
     * This is the mechanism that makes a deploy drop the old cache.
     */
    public function test_the_cache_version_changes_when_the_cached_files_change(): void
    {
        $before = Pwa::cacheVersion();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $before);

        // A different set of precached files must fingerprint differently. The
        // theme toggle is precached and is not content-hashed, so it stands in
        // for "something cached changed".
        $toggle = public_path('assets/js/theme-toggle.js');
        $original = file_get_contents($toggle);

        try {
            file_put_contents($toggle, $original . "\n/* touched by a test */\n");

            $this->assertNotSame(
                $before,
                $this->freshCacheVersion(),
                'editing a precached file left the service-worker version unchanged'
            );
        } finally {
            file_put_contents($toggle, $original);
        }

        $this->assertSame($before, $this->freshCacheVersion(), 'the version did not settle back');
    }

    // ------------------------------------------------------------- caching --

    /**
     * The precache may hold only files a signed-out visitor could already
     * fetch. This is the list that ends up on the device, so it is worth
     * asserting positively rather than trusting the rule that built it.
     */
    public function test_nothing_private_is_precached(): void
    {
        $paths = array_map(
            fn (string $url) => (string) parse_url($url, PHP_URL_PATH),
            Pwa::precacheUrls()
        );

        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->assertMatchesRegularExpression(
                '#^/(offline|assets/|build/)#',
                $path,
                "{$path} is precached but is not a public static asset"
            );
        }

        foreach (['/applicant', '/provider', '/admin', '/files', '/login', '/applications', '/storage'] as $private) {
            foreach ($paths as $path) {
                $this->assertStringNotContainsString($private, $path);
            }
        }
    }

    /**
     * Pages are never cached - not the dashboard, not an application, not a
     * provider's inbox. A cached page outlives the session that was allowed to
     * see it, which on a shared phone is the whole problem.
     */
    public function test_the_worker_takes_navigations_to_the_network_and_never_stores_them(): void
    {
        $source = file_get_contents(Pwa::serviceWorkerSourcePath());

        // A navigation is answered by fetch(), with the offline page as the
        // only fallback - there is no cache read and no cache write on the path.
        $this->assertMatchesRegularExpression(
            "#request\.mode === 'navigate'#",
            $source
        );

        $navigationBlock = substr($source, (int) strpos($source, "request.mode === 'navigate'"));
        $navigationBlock = substr($navigationBlock, 0, (int) strpos($navigationBlock, 'CACHEABLE_PREFIXES.some'));

        $this->assertStringNotContainsString('cache.put', $navigationBlock, 'a navigation response is being written to a cache');
        $this->assertStringContainsString('fetch(request)', $navigationBlock);
        $this->assertStringContainsString('OFFLINE_URL', $navigationBlock);
    }

    /** Anything that is not a same-origin GET is handed straight back to the browser. */
    public function test_the_worker_ignores_writes_and_other_origins(): void
    {
        $source = file_get_contents(Pwa::serviceWorkerSourcePath());

        $this->assertStringContainsString("if (request.method !== 'GET') return;", $source);
        $this->assertStringContainsString('if (url.origin !== self.location.origin) return;', $source);
    }

    /** Runtime caching is an allow-list of static prefixes, not a heuristic. */
    public function test_only_static_prefixes_may_be_cached_at_runtime(): void
    {
        $source = file_get_contents(Pwa::serviceWorkerSourcePath());

        $this->assertStringContainsString("const CACHEABLE_PREFIXES = ['/build/', '/assets/'];", $source);
    }

    // -------------------------------------------------------- offline page --

    public function test_the_offline_page_renders_and_says_what_to_do(): void
    {
        $response = $this->get('/offline');

        $response->assertOk();
        $response->assertSee('offline', false);
        $response->assertSee('Reconnect to the internet to continue using ScholarZim', false);
    }

    /**
     * One copy of this page is stored per device, not per account, and it
     * outlives the session that fetched it. So it must render identically
     * whether or not somebody is signed in.
     */
    public function test_the_offline_page_leaks_nothing_about_the_signed_in_user(): void
    {
        $this->seed(DatabaseSeeder::class);
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $guest = $this->get('/offline')->getContent();
        $signedIn = $this->actingAs($student)->get('/offline')->getContent();

        $this->assertSame($guest, $signedIn, 'the offline page differs for a signed-in user, so it must not be cached');
        $this->assertStringNotContainsString($student->email, $signedIn);
        $this->assertStringNotContainsString('csrf', strtolower($signedIn));
    }

    // ------------------------------------------------------------ metadata --

    /**
     * The metadata lives in the one partial every layout includes. A layout that
     * assembled its own would be installable everywhere except the page a reader
     * happened to be on when they went looking for the button.
     */
    public function test_the_pwa_metadata_comes_from_the_shared_partial(): void
    {
        $partial = file_get_contents(resource_path('views/partials/assets.blade.php'));

        $this->assertStringContainsString("route('pwa.manifest')", $partial);
        $this->assertStringContainsString('theme-color', $partial);
        $this->assertStringContainsString('apple-touch-icon', $partial);

        foreach (glob(resource_path('views/layouts/*.blade.php')) as $layout) {
            $this->assertStringNotContainsString(
                'rel="manifest"',
                (string) file_get_contents($layout),
                basename($layout) . ' declares its own manifest link - it belongs in partials/assets.blade.php'
            );
        }
    }

    /** Every kind of page a reader can land on offers the install. */
    public function test_the_manifest_and_theme_colour_reach_every_layout(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pages = [
            '/' => null,                                                   // public
            '/login' => null,                                              // auth
            '/applicant/dashboard' => User::where('email', 'student@scholarzim.co.zw')->firstOrFail(),
        ];

        foreach ($pages as $url => $actor) {
            $response = $actor ? $this->actingAs($actor)->get($url) : $this->get($url);

            $response->assertOk();
            $response->assertSee('rel="manifest"', false, "{$url} carries no manifest link");
            $response->assertSee('name="theme-color"', false, "{$url} carries no theme colour");
        }
    }

    /** The error layout too - it is the page least likely to be looked at. */
    public function test_the_error_pages_carry_the_manifest(): void
    {
        $this->assertStringContainsString('rel="manifest"', view('errors.404')->render());
    }

    // ------------------------------------------------------------ security --

    /**
     * The worker and the manifest are both same-origin, so 'self' already
     * covers them - worker-src and manifest-src fall back to script-src and
     * default-src respectively. This test is here so that stays true: a future
     * CSP change that breaks the install would otherwise show up as a missing
     * button rather than as an error.
     */
    public function test_the_content_security_policy_still_allows_the_worker_and_manifest(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);

        // No exception was needed, and none should creep in.
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    /** The PWA routes are public, but they must stay read-only and session-free. */
    public function test_the_pwa_routes_expose_no_records(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['/manifest.webmanifest', '/service-worker.js', '/offline'] as $url) {
            $body = $this->get($url)->getContent();

            foreach (['student@scholarzim.co.zw', 'provider@scholarzim.co.zw', 'admin@scholarzim.co.zw'] as $email) {
                $this->assertStringNotContainsString($email, $body);
            }
        }
    }

    // --------------------------------------- the app it is wrapped around --

    /**
     * The PWA layer is additive, and these are the paths it sits closest to.
     * Authentication, the application workflow and ScholarFit each have their
     * own suite; what is checked here is only that adding the worker and the
     * head metadata did not disturb them.
     */
    public function test_signing_in_still_works_with_the_pwa_metadata_in_the_head(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->post('/login', ['email' => 'student@scholarzim.co.zw', 'password' => 'ChangeMe123'])
            ->assertRedirect('/applicant/dashboard');

        $this->assertAuthenticated();
    }

    public function test_the_service_worker_does_not_shadow_a_real_route(): void
    {
        // /offline, /service-worker.js and /manifest.webmanifest are new paths;
        // none of them may have taken a name or a URI something else was using.
        $this->get('/')->assertOk();
        $this->get('/scholarships')->assertOk();
        $this->get('/health')->assertOk();
    }

    /**
     * cacheVersion() memoises for the life of the process, which is what keeps
     * it cheap on a real request but means it cannot be asked twice here. This
     * recomputes it the same way rather than reaching inside to reset it: if the
     * two ever disagree, the assertion that uses this is the one that should
     * fail.
     */
    private function freshCacheVersion(): string
    {
        $parts = [(string) md5_file(Pwa::serviceWorkerSourcePath())];

        foreach (Pwa::precacheUrls() as $url) {
            $parts[] = $url;
            $relative = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

            if (! str_starts_with($relative, 'build/') && is_file(public_path($relative))) {
                $parts[] = (string) md5_file(public_path($relative));
            }
        }

        return substr(md5(implode('|', $parts)), 0, 12);
    }
}
