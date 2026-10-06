<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The landing page's photographs: local files, present, and actually the
 * ones the page references - never a hotlinked URL standing in for them.
 */
class LandingAssetsTest extends TestCase
{
    use RefreshDatabase;

    private const ASSETS = [
        'hero-student.jpg',
        'scholarfit-student.jpg',
        'student-section.jpg',
        'provider-section.jpg',
        'cta-background.jpg',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_landing_page_renders(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_every_landing_asset_exists_and_is_a_readable_image(): void
    {
        foreach (self::ASSETS as $file) {
            $path = public_path('assets/landing/' . $file);

            $this->assertFileExists($path);
            $size = getimagesize($path);
            $this->assertNotFalse($size, $file . ' must be a valid image');
            $this->assertGreaterThan(0, $size[0]);
            $this->assertGreaterThan(0, $size[1]);
        }
    }

    /**
     * The originals were 159-410px wide and the hero was stretched about 4x.
     * Each file now has to be genuinely large: the full-bleed hero most of all.
     */
    public function test_every_landing_asset_meets_its_minimum_resolution(): void
    {
        $minimumWidth = [
            'hero-student.jpg' => 2000,
            'scholarfit-student.jpg' => 1200,
            'student-section.jpg' => 1200,
            'provider-section.jpg' => 1200,
            'cta-background.jpg' => 1200,
        ];

        $this->assertSame(array_keys($minimumWidth), self::ASSETS);

        foreach ($minimumWidth as $file => $width) {
            [$actualWidth, $actualHeight] = getimagesize(public_path('assets/landing/' . $file));

            $this->assertGreaterThanOrEqual($width, $actualWidth, $file . ' is only ' . $actualWidth . 'px wide');
            $this->assertGreaterThanOrEqual(600, $actualHeight, $file . ' is only ' . $actualHeight . 'px tall');
        }

        $this->assertGreaterThan(
            getimagesize(public_path('assets/landing/scholarfit-student.jpg'))[0],
            getimagesize(public_path('assets/landing/hero-student.jpg'))[0],
            'the hero must be the widest asset'
        );
    }

    /** Photographs are optimised for the web: large pixel counts, modest bytes. */
    public function test_landing_assets_are_web_optimised(): void
    {
        $total = 0;

        foreach (self::ASSETS as $file) {
            $bytes = filesize(public_path('assets/landing/' . $file));
            $total += $bytes;

            $this->assertLessThan(700 * 1024, $bytes, $file . ' is heavier than 700 KB');
        }

        $this->assertLessThan(1500 * 1024, $total, 'the five landing images together exceed 1.5 MB');
    }

    /** The attribution record exists and names every file. */
    public function test_the_image_credits_file_names_every_asset(): void
    {
        $credits = file_get_contents(base_path('docs/landing-image-credits.md'));

        foreach (self::ASSETS as $file) {
            $this->assertStringContainsString('`' . $file . '`', $credits);
        }

        $this->assertStringContainsString('CC0', $credits);
    }

    public function test_the_landing_page_references_each_local_asset(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (self::ASSETS as $file) {
            $this->assertStringContainsString('assets/landing/' . $file, $html, $file . ' must be referenced by the landing page');
        }
    }

    /** No photograph on the page comes from another host. */
    public function test_no_landing_image_is_hotlinked(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('/<img[^>]+src="([^"]+)"/i', $html, $imgs);
        preg_match_all('/url\([\'"]?([^\'")]+)[\'"]?\)/i', $html, $css);

        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        foreach (array_merge($imgs[1], $css[1]) as $url) {
            $host = parse_url(html_entity_decode($url), PHP_URL_HOST);

            $this->assertTrue(
                $host === null || $host === $appHost || $host === 'localhost' || $host === '127.0.0.1' || $host === 'testserver',
                'landing image must be a local asset, found ' . $url
            );
        }
    }

    /** The old filenames are the only ones referenced - no stale or misspelled leftovers. */
    public function test_the_page_references_no_unknown_landing_file(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('#assets/landing/([A-Za-z0-9._-]+)#', $html, $m);

        foreach (array_unique($m[1]) as $file) {
            $this->assertContains($file, self::ASSETS, $file . ' is referenced but not a known landing asset');
        }
    }
}
