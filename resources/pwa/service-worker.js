/*
 * ScholarZim's service worker.
 *
 * Served by PwaController, not by Vite: a service worker's URL has to stay
 * stable across deploys or the browser treats each build as a different worker,
 * and Vite content-hashes everything it touches. The controller prepends two
 * constants before this file:
 *
 *     SZ_VERSION   - changes whenever anything cached would change
 *     SZ_PRECACHE  - the exact list of URLs allowed on the device
 *
 * WHAT IS CACHED, AND WHY
 *
 * Only files that are identical for every visitor: the vendor theme, the
 * content-hashed bundle, the icons, and the offline page. All of it is public
 * already - a signed-out visitor can fetch any of it - so a shared phone learns
 * nothing from the cache that it could not learn from the login page.
 *
 * WHAT IS DELIBERATELY NOT CACHED
 *
 * Every HTML page, without exception. Not the dashboard, not a scholarship
 * listing, not an application, not a provider's inbox. Pages are where the
 * private data is, and a cache is the wrong place for anything whose visibility
 * depends on who is signed in - a cached page outlives the session that was
 * allowed to see it. So navigations go to the network, and when the network is
 * gone the reader gets the offline page rather than a stale copy of someone's
 * application history.
 *
 * Also never intercepted: anything that is not a same-origin GET. That covers
 * every form post (sign-in, applying, a provider's decision, uploads), every
 * cross-origin request, and the document downloads under /files - none of which
 * this worker has any business seeing.
 */

const PRECACHE = `scholarzim-precache-${SZ_VERSION}`;
const RUNTIME = `scholarzim-runtime-${SZ_VERSION}`;

/**
 * Path prefixes that may be filled into the runtime cache on first use.
 *
 * An allow-list, not a heuristic. /build is Vite's output and is content-hashed,
 * so a stale entry is impossible by construction; /assets is the committed
 * vendor theme and branding, which is public and changes only on deploy - and
 * the deploy changes SZ_VERSION, which drops the whole cache.
 */
const CACHEABLE_PREFIXES = ['/build/', '/assets/'];

const OFFLINE_URL = SZ_PRECACHE[0];

/**
 * Fills the precache one entry at a time.
 *
 * Not cache.addAll: that rejects as a unit, so a single 404 - an icon not yet
 * regenerated, say - would leave the worker with no offline page at all rather
 * than with most of one.
 *
 * Not a parallel Promise.all either, which is the same trap one level up. The
 * install fires while the page that triggered it is still loading, so a dozen
 * simultaneous requests land on a server already busy serving that page. Against
 * `php artisan serve`, which handles one request at a time, every one of them
 * fails and the precache ends up empty - silently, because each failure is
 * caught. Sequential fetches cost a moment on first load and are the difference
 * between a worker that installs anywhere and one that only installs against a
 * multi-worker server.
 */
const fillPrecache = async cache => {
    for (const url of SZ_PRECACHE) {
        try {
            await cache.add(url);
        } catch {
            // One missing asset is not worth failing the install over: the
            // fetch handler falls through to the network for anything absent.
        }
    }
};

self.addEventListener('install', event => {
    event.waitUntil(
        caches
            .open(PRECACHE)
            .then(fillPrecache)
            // Take over as soon as the new assets are in place. The alternative
            // is a worker that waits for every tab to close, which on an
            // installed app can be days - and until then the previous deploy is
            // what the device serves.
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches
            .keys()
            .then(names =>
                Promise.all(
                    names
                        .filter(name => name.startsWith('scholarzim-') && name !== PRECACHE && name !== RUNTIME)
                        .map(name => caches.delete(name)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', event => {
    const request = event.request;

    // Not ours to touch. Leaving the event unhandled hands it back to the
    // browser untouched, which is what every one of these should get.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;

    // Pages: network only. See the header comment - nothing that renders a
    // session is allowed into a cache.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches.match(OFFLINE_URL).then(
                    cached =>
                        cached ||
                        new Response('You’re offline. Reconnect to the internet to continue using ScholarZim.', {
                            status: 503,
                            headers: { 'Content-Type': 'text/plain; charset=utf-8' },
                        }),
                ),
            ),
        );
        return;
    }

    if (!CACHEABLE_PREFIXES.some(prefix => url.pathname.startsWith(prefix))) return;

    // Static assets: cache first. They are either content-hashed or dropped
    // wholesale by the next deploy's SZ_VERSION, so serving from the cache
    // cannot pin an old file in place.
    event.respondWith(
        caches.match(request).then(cached => {
            if (cached) return cached;

            return fetch(request).then(response => {
                if (response.ok && response.type === 'basic') {
                    const copy = response.clone();
                    caches.open(RUNTIME).then(cache => cache.put(request, copy));
                }

                return response;
            });
        }),
    );
});

/**
 * Lets the page trigger an immediate takeover after it has told the reader an
 * update is ready. Without it a waiting worker sits until every tab closes.
 */
self.addEventListener('message', event => {
    if (event.data === 'scholarzim:skip-waiting') self.skipWaiting();
});
