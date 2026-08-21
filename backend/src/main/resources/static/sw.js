/* Legacy cleanup worker — unregisters and deletes all caches, does not intercept requests. */
self.addEventListener('install', function (event) {
    self.skipWaiting();
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (k) { return caches.delete(k); }));
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (k) { return caches.delete(k); }));
        }).then(function () {
            return self.registration.unregister();
        })
    );
});
