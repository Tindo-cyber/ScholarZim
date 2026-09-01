<?php

namespace App\Http\Controllers;

use App\Support\Pwa;
use Illuminate\Http\Response;

/**
 * The three files a browser asks for before it will offer to install ScholarZim.
 *
 * They are served by a controller rather than dropped into public/ for two
 * reasons. The first is content types: a manifest has to arrive as
 * application/manifest+json and a worker as JavaScript, and nginx's stock
 * mime.types does not map .webmanifest at all - a detail that costs nothing here
 * and is invisible until the install button fails to appear in production. The
 * second is the version stamp. The service worker has to know which build it is
 * caching, and the only place that can be worked out is on the server, at the
 * moment it is served.
 *
 * This is the same shape as SourceAssetController: a small, public, explicitly
 * enumerated set of files served through Laravel. It is not, and must not grow
 * into, an API - nothing here reads a session or touches a record.
 */
class PwaController extends Controller
{
    public function manifest(): Response
    {
        return response(
            (string) json_encode(Pwa::manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            200,
            [
                'Content-Type' => 'application/manifest+json; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
            ]
        );
    }

    /**
     * The worker, with its version and precache list prepended.
     *
     * `no-cache` rather than a max-age: browsers already refuse to trust a
     * cached worker for more than a day, and a deploy should not have to wait
     * out the remainder of that day to reach a device. The response is small and
     * revalidates cheaply.
     *
     * Service-Worker-Allowed is sent for completeness. The route is registered at
     * the site root, so the default scope is already "/" - but stating it means
     * the scope survives being moved behind a path prefix later.
     */
    public function serviceWorker(): Response
    {
        $source = (string) @file_get_contents(Pwa::serviceWorkerSourcePath());

        abort_if($source === '', 404);

        $header = sprintf(
            "const SZ_VERSION = %s;\nconst SZ_PRECACHE = %s;\n\n",
            json_encode(Pwa::cacheVersion()),
            json_encode(Pwa::precacheUrls(), JSON_UNESCAPED_SLASHES)
        );

        return response($header . $source, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    /**
     * The page shown when a navigation cannot reach the server.
     *
     * Held in the precache, which is why it renders nothing that depends on who
     * is signed in: one copy is stored per device, not per account, and it
     * outlives the session that fetched it.
     */
    public function offline(): Response
    {
        return response(view('pwa.offline')->render());
    }
}
