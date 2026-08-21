(function () {
    var purge = document.documentElement.getAttribute("data-sz-purge-cache") === "true";
    if (!purge) return;
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(function (regs) {
            regs.forEach(function (r) { r.unregister(); });
        });
    }
    if ('caches' in window) {
        caches.keys().then(function (keys) {
            keys.forEach(function (k) { caches.delete(k); });
        });
    }
})();
