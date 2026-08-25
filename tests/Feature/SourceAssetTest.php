<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The fallback asset path, used when no Vite build is present.
 *
 * It reads from resources/, which also holds Blade templates, so the whitelist
 * is the whole security story here and is what these tests are about.
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
}
