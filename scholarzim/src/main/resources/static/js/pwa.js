(function () {
    'use strict';

    var ASSET_VERSION = 'v39';

    function purgeCaches() {
        if (!('caches' in window)) return Promise.resolve();
        return caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (k) { return caches.delete(k); }));
        });
    }

    function unregisterAll() {
        if (!('serviceWorker' in navigator)) return Promise.resolve();
        return navigator.serviceWorker.getRegistrations().then(function (regs) {
            return Promise.all(regs.map(function (r) { return r.unregister(); }));
        });
    }

    /* Always purge legacy PWA caches — prevents old CSS from returning after navigation */
    Promise.all([unregisterAll(), purgeCaches()]).then(function () {
        localStorage.setItem('sz-asset-version', ASSET_VERSION);
    }).catch(function () {});

    var banner = document.getElementById('pwa-install-banner');
    var dismissed = localStorage.getItem('sz-pwa-dismissed');

    if (banner && !dismissed && window.matchMedia('(max-width: 767px)').matches) {
        var deferredPrompt = null;

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredPrompt = e;
            banner.classList.remove('d-none');
        });

        if (window.matchMedia('(display-mode: standalone)').matches) {
            banner.classList.add('d-none');
        }

        var installBtn = document.getElementById('pwa-install-btn');
        var dismissBtn = document.getElementById('pwa-dismiss-btn');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                banner.classList.add('d-none');
                localStorage.setItem('sz-pwa-dismissed', '1');
            });
        }
        if (installBtn) {
            installBtn.addEventListener('click', function () {
                if (deferredPrompt) {
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.finally(function () {
                        deferredPrompt = null;
                        banner.classList.add('d-none');
                    });
                } else {
                    banner.classList.add('d-none');
                }
            });
        }
    }
})();
