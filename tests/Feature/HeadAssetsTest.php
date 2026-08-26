<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * That every page on the platform actually receives the stylesheets it asks for.
 *
 * Both bugs these tests exist for were invisible from the page that introduced
 * them. The error layout asked for a public/assets/css/scholarzim.css that has
 * never existed, so every 404 and 500 rendered without ScholarZim's own styles;
 * the auth layout was the only one omitting theme-toggle.js, so signing in
 * ignored a saved dark mode. Nobody reloads a 404 page while working, which is
 * exactly why a test has to be the thing that notices.
 */
class HeadAssetsTest extends TestCase
{
    /**
     * Every asset() path in every Blade view resolves to a real file. A dead
     * path is a silent 404 - the browser drops the stylesheet and the page keeps
     * rendering, styled just enough to look intentional.
     */
    public function test_no_view_references_an_asset_that_is_not_there(): void
    {
        $missing = [];

        foreach ($this->bladeViews() as $view) {
            preg_match_all("#asset\('([^']+)'\)#", (string) file_get_contents($view), $matches);

            foreach ($matches[1] as $path) {
                if (! is_file(public_path($path))) {
                    $missing[] = basename(dirname($view)) . '/' . basename($view) . ' -> ' . $path;
                }
            }
        }

        $this->assertSame([], $missing, "views reference assets that are not in public/:\n" . implode("\n", $missing));
    }

    /**
     * No layout assembles its own head asset list. This is the constraint that
     * keeps the two bugs above from coming back in a new layout: there is one
     * partial, and a layout either includes it or has no styles at all - which
     * is loud, unlike a single missing line among five correct ones.
     */
    public function test_every_layout_takes_its_head_assets_from_the_shared_partial(): void
    {
        $layouts = array_merge(
            glob(resource_path('views/layouts/*.blade.php')),
            [resource_path('views/errors/layout.blade.php')],
        );

        $this->assertNotEmpty($layouts);

        foreach ($layouts as $layout) {
            $contents = (string) file_get_contents($layout);
            $name = basename($layout);

            $this->assertStringContainsString(
                "@include('partials.assets')",
                $contents,
                "{$name} does not include the shared head-assets partial"
            );

            $this->assertDoesNotMatchRegularExpression(
                '#<link[^>]+rel="stylesheet"#',
                $contents,
                "{$name} links a stylesheet of its own - it belongs in partials/assets.blade.php"
            );
        }
    }

    /**
     * The error pages, end to end. They are the pages most likely to be styled
     * wrong and least likely to be looked at.
     */
    public function test_every_error_page_gets_the_full_stylesheet_set(): void
    {
        foreach (['403', '404', '419', '500', '503'] as $code) {
            $html = view("errors.{$code}")->render();

            $this->assertStringContainsString('bvite-base.css', $html, "the {$code} page is missing the vendor theme");
            $this->assertMatchesRegularExpression(
                '#/assets/source/scholarzim\.css|/build/assets/#',
                $html,
                "the {$code} page is missing ScholarZim's own CSS"
            );
            $this->assertStringContainsString('theme-toggle.js', $html, "the {$code} page will flash the wrong theme");
        }
    }

    /**
     * The vendor theme has to be linked before ScholarZim's overlay: both define
     * a bare `main`, so equal specificity makes document order the whole reason
     * the overlay wins. Reversed, the theme's own dashboard grid takes over and
     * every page collapses into its 120px first column.
     */
    public function test_the_overlay_is_linked_after_the_vendor_theme(): void
    {
        $html = view('partials.assets')->render();

        $vendor = strpos($html, 'bvite-base.css');
        preg_match('#/assets/source/scholarzim\.css|/build/assets/[^"]+\.css#', $html, $match, PREG_OFFSET_CAPTURE);

        $this->assertNotFalse($vendor);
        $this->assertNotEmpty($match, 'no ScholarZim stylesheet was emitted at all');
        $this->assertGreaterThan($vendor, $match[0][1], 'the overlay is linked before the vendor theme it overrides');
    }

    /** @return list<string> */
    private function bladeViews(): array
    {
        $views = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $views[] = $file->getPathname();
            }
        }

        sort($views);

        return $views;
    }
}
