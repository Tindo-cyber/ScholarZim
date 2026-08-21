(function () {
    var t = localStorage.getItem('sz-theme') || 'light';
    document.documentElement.setAttribute('data-bs-theme', t);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', t === 'dark' ? '#0f172a' : '#16A34A');
})();
