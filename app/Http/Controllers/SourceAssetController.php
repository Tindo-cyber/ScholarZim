<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves ScholarZim's own CSS and JS straight from resources/, for the case
 * where no Vite build has been made.
 *
 * Why this exists: @vite() throws when there is no manifest, which would turn a
 * forgotten `npm run build` into a 500 on every page - including a reviewer's
 * first run of `php artisan serve` after `composer install`. Rather than keeping
 * a duplicate copy of every asset under public/, the source files are served
 * directly: unminified and unhashed, but correct.
 *
 * Only the built path is used in production, where the entrypoint builds the
 * assets; this route stays registered because a deploy that lost its manifest
 * should degrade to a working site rather than a blank one.
 */
class SourceAssetController extends Controller
{
    /**
     * Exactly what may be served, and from where. A whitelist rather than a path
     * under resources/ that the request gets to choose - resources/ also holds
     * Blade templates and the .env-adjacent config a caller must never reach.
     */
    private const ASSETS = [
        'scholarzim.css' => ['css/scholarzim.css', 'text/css'],
        'scholarzim.js' => ['js/scholarzim.js', 'text/javascript'],
        'profile-form.js' => ['js/profile-form.js', 'text/javascript'],
        'scholarfit-weights.js' => ['js/scholarfit-weights.js', 'text/javascript'],
        'bulk-select.js' => ['js/bulk-select.js', 'text/javascript'],
        'pwa.js' => ['js/pwa.js', 'text/javascript'],
    ];

    /** The scripts the fallback loads, in the order app.js imports them. */
    public const FALLBACK_SCRIPTS = [
        'scholarzim.js',
        'profile-form.js',
        'scholarfit-weights.js',
        'bulk-select.js',
        'pwa.js',
    ];

    public function show(string $asset): Response
    {
        abort_unless(array_key_exists($asset, self::ASSETS), 404);

        [$relativePath, $mimeType] = self::ASSETS[$asset];
        $path = resource_path($relativePath);

        abort_unless(is_readable($path), 404);

        return response(file_get_contents($path), 200, [
            'Content-Type' => $mimeType . '; charset=utf-8',
            // Short cache only: this path exists precisely because the content is
            // not content-hashed, so it must not be held on to.
            'Cache-Control' => 'public, max-age=60',
        ]);
    }
}
