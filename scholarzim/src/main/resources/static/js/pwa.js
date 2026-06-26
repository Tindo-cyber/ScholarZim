(function () {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js').catch(function () {});
    });
  }

  var banner = document.getElementById('pwaInstallBanner');
  var dismissed = localStorage.getItem('sz-pwa-dismissed');

  if (banner && !dismissed && window.matchMedia('(max-width: 767px)').matches) {
    banner.hidden = false;
    banner.querySelector('[data-pwa-dismiss]')?.addEventListener('click', function () {
      banner.hidden = true;
      localStorage.setItem('sz-pwa-dismissed', '1');
    });
  }
})();
