/* Network-first; never cache HTML or CSS/JS */
const CACHE = 'scholarzim-v36';

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (k) { return caches.delete(k); }));
        }).then(function () { return self.clients.claim(); })
    );
});

function isStaticAsset(url) {
    return url.pathname.startsWith('/css/') ||
        url.pathname.startsWith('/js/') ||
        url.pathname.startsWith('/icons/');
}

function isHtmlRequest(request) {
    if (request.mode === 'navigate') return true;
    var accept = request.headers.get('accept');
    return accept && accept.indexOf('text/html') !== -1;
}

self.addEventListener('fetch', function (event) {
    if (event.request.method !== 'GET') return;

    var url = new URL(event.request.url);

    /* Always fetch fresh styles, scripts, and pages */
    if (isStaticAsset(url) || isHtmlRequest(event.request)) {
        event.respondWith(
            fetch(event.request, { cache: 'no-store' }).catch(function () {
                return caches.match(event.request);
            })
        );
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then(function (response) {
                return response;
            })
            .catch(function () { return caches.match(event.request); })
    );
});
