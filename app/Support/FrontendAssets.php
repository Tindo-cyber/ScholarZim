<?php

namespace App\Support;

/**
 * Decides which of the three ways ScholarZim's own CSS/JS can be delivered is
 * actually available: the Vite dev server, a production build, or the source
 * files served individually by SourceAssetController.
 *
 * Why this is not a pair of file_exists() calls. Laravel treats the presence of
 * public/hot as "the dev server is running", and Vite only removes that file on
 * a clean shutdown - close the terminal, kill the process, or reboot mid
 * `npm run dev` and it stays behind for good. Every later page then points its
 * stylesheet at a dev server that is not listening, which loses the app-shell
 * overlay in resources/css/scholarzim.css while the vendor theme keeps loading
 * from public/assets: a site that is styled enough to look intentional and
 * broken enough to be unusable. A collaborator who pulls and runs the dev server
 * once inherits that state permanently, so the hot file is trusted only when
 * something is really listening on the other end.
 *
 * A manifest is checked the same way, against the files it names rather than its
 * own existence, so a half-deleted public/build degrades instead of emitting
 * 404s for every asset.
 */
final class FrontendAssets
{
    /**
     * How long to wait for the dev server to accept a connection. Generous for a
     * loopback connect - which either answers immediately or not at all - and
     * short enough that a dead hot file cannot hold a page render open.
     */
    private const PROBE_TIMEOUT_SECONDS = 0.25;

    /** The Vite entry points, as named in vite.config.js. */
    private const ENTRY_POINTS = [
        'resources/css/app.css',
        'resources/js/app.js',
    ];

    private function __construct()
    {
    }

    /**
     * Whether @vite() can be called at all. False means the caller must emit the
     * SourceAssetController fallback instead.
     */
    public static function viteReady(): bool
    {
        return self::viteReadyIn(public_path());
    }

    /**
     * The same decision against an arbitrary public directory, so the behaviour
     * can be tested without touching the real public/.
     */
    public static function viteReadyIn(string $publicDir): bool
    {
        $hotFile = $publicDir . DIRECTORY_SEPARATOR . 'hot';

        if (is_file($hotFile)) {
            if (self::devServerIsListening($hotFile)) {
                return true;
            }

            // The file is a leftover. Remove it so @vite() stops preferring the
            // dev server over the build, and so the probe above is not repeated
            // on every subsequent request.
            @unlink($hotFile);

            // If it could not be removed - a read-only deploy, say - @vite()
            // would still emit dev server URLs, so the fallback is the only
            // safe answer regardless of whether a build is present.
            if (is_file($hotFile)) {
                return false;
            }
        }

        return self::buildIsComplete($publicDir);
    }

    /**
     * Whether anything is accepting connections at the address in the hot file.
     * A connection is opened and dropped without speaking HTTP: reaching the
     * socket is the whole question, and a request would only add latency.
     */
    private static function devServerIsListening(string $hotFile): bool
    {
        $url = trim((string) @file_get_contents($hotFile));

        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if ($host === null) {
            return false;
        }

        $port = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);

        $socket = @fsockopen($host, (int) $port, $errno, $errstr, self::PROBE_TIMEOUT_SECONDS);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Whether the manifest exists *and* the files it names are on disk. Checked
     * together because a manifest naming a missing chunk is worse than no
     * manifest at all: @vite() emits the tags happily and every one 404s.
     */
    private static function buildIsComplete(string $publicDir): bool
    {
        $buildDir = $publicDir . DIRECTORY_SEPARATOR . 'build';
        $manifestPath = $buildDir . DIRECTORY_SEPARATOR . 'manifest.json';

        if (! is_file($manifestPath)) {
            return false;
        }

        $manifest = json_decode((string) @file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            return false;
        }

        foreach (self::ENTRY_POINTS as $entry) {
            $file = $manifest[$entry]['file'] ?? null;

            if (! is_string($file) || ! is_file($buildDir . DIRECTORY_SEPARATOR . $file)) {
                return false;
            }
        }

        return true;
    }
}
