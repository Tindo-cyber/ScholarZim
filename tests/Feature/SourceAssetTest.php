<?php

namespace Tests\Feature;

use App\Http\Controllers\SourceAssetController;
use Tests\TestCase;

/**
 * The fallback asset path, used when no Vite build is present - which is every
 * fresh clone until someone runs `npm run build`.
 *
 * Two things are being guarded. It reads from resources/, which also holds Blade
 * templates, so the whitelist is the whole security story. And it is the path a
 * collaborator is on by default, so what it serves has to stay in step with the
 * bundle it stands in for.
 */
class SourceAssetTest extends TestCase
{
    public function test_a_whitelisted_asset_is_served_with_its_own_content_type(): void
    {
        $this->get('/assets/source/scholarzim.css')
            ->assertOk()
            ->assertHeader('content-type', 'text/css; charset=utf-8');

        $this->get('/assets/source/bulk-select.js')
            ->assertOk()
            ->assertHeader('content-type', 'text/javascript; charset=utf-8');
    }

    public function test_anything_outside_the_whitelist_is_a_404(): void
    {
        $this->get('/assets/source/app.js')->assertNotFound();
        $this->get('/assets/source/openapi.json')->assertNotFound();
    }

    /**
     * The route pattern excludes slashes, so a traversal attempt never reaches
     * the controller in the first place - it simply does not match the route.
     */
    public function test_a_traversal_attempt_does_not_match_the_route(): void
    {
        $this->get('/assets/source/..%2F..%2F.env')->assertNotFound();
        $this->get('/assets/source/views/layouts/app.blade.php')->assertNotFound();
    }

    /**
     * The asset tags against the real public/, for the state a collaborator
     * lands in after running the dev server once and stopping it: a hot file
     * with nothing behind it must not reach the page, and must not survive the
     * render either.
     */
    public function test_a_dead_hot_file_never_reaches_the_page(): void
    {
        $hotFile = public_path('hot');

        if (file_exists($hotFile)) {
            $this->markTestSkipped('a Vite dev server owns public/hot right now');
        }

        $deadServer = 'http://127.0.0.1:' . $this->closedPort();
        file_put_contents($hotFile, $deadServer);

        try {
            $html = view('partials.assets')->render();
        } finally {
            @unlink($hotFile);
        }

        $this->assertStringNotContainsString($deadServer, $html);
        $this->assertMatchesRegularExpression(
            '#/assets/source/scholarzim\.css|/build/assets/#',
            $html,
            "the page must still get ScholarZim's own CSS from somewhere"
        );
        $this->assertFileDoesNotExist($hotFile, 'the stale hot file should have been cleaned up');
    }

    /**
     * The fallback list is a hand-maintained copy of what app.js imports, and it
     * is the path every collaborator is on until their first `npm run build` -
     * so a script added to the bundle and forgotten here would go missing for
     * exactly the people least likely to notice it was ever there.
     */
    public function test_the_fallback_scripts_match_what_the_bundle_imports(): void
    {
        $entry = file_get_contents(resource_path('js/app.js'));

        preg_match_all("#^\s*import\s+'\./([A-Za-z0-9.\-]+)';#m", $entry, $matches);

        $imported = array_map(
            fn (string $module) => str_ends_with($module, '.js') ? $module : $module . '.js',
            $matches[1]
        );

        $this->assertNotEmpty($imported, 'no imports found in app.js - has the entry point moved?');
        $this->assertSame(
            $imported,
            SourceAssetController::FALLBACK_SCRIPTS,
            'FALLBACK_SCRIPTS has drifted from the imports in resources/js/app.js'
        );
    }

    /** A port nothing is listening on: bound to learn its number, then released. */
    private function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT);
        fclose($server);

        return $port;
    }
}
