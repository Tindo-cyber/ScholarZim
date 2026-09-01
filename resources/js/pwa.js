/**
 * Service-worker registration and the install affordance.
 *
 * Everything here is additive: if the browser has no service worker, no install
 * event, or no support at all, nothing runs and ScholarZim is the same website
 * it was. That is the point - the worker caches static files and shows an
 * offline page, so a browser without one is missing a convenience, not a
 * feature.
 *
 * There is deliberately no "a new version is available, reload" prompt. The
 * worker never caches HTML, so a page always comes from the network and cannot
 * be a deploy behind; the only cached things are content-hashed bundles and
 * assets whose cache is dropped wholesale when the version changes. Reloading
 * the page underneath somebody would risk throwing away a half-written
 * application to fix a problem that cannot occur.
 */

/** Remembers a dismissal, so the offer is made once rather than on every page. */
const DISMISSED_KEY = 'scholarzim:install-dismissed';

const readDismissed = () => {
    try {
        return localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        // Private mode, or storage disabled. Treat it as "not dismissed" and
        // let the button show; the alternative is throwing during startup.
        return false;
    }
};

const writeDismissed = () => {
    try {
        localStorage.setItem(DISMISSED_KEY, '1');
    } catch {
        /* nothing to do - the offer simply reappears next time */
    }
};

const registerServiceWorker = () => {
    if (!('serviceWorker' in navigator)) return;

    // Registration is not urgent and competes with the page's own requests, so
    // it waits for load rather than racing first paint.
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js', { scope: '/' }).catch(() => {
            /* An unregistered worker costs the offline page and nothing else. */
        });
    });
};

const wireInstallPrompt = () => {
    const buttons = document.querySelectorAll('[data-pwa-install]');
    if (!buttons.length) return;

    let deferredPrompt = null;

    const hide = () => buttons.forEach(button => (button.hidden = true));

    // Fired by Chromium browsers when the app meets the install criteria and is
    // not installed already. Safari and Firefox never fire it, so the button
    // simply stays hidden there and the reader uses the browser's own
    // "Add to Home Screen".
    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();

        if (readDismissed()) return;

        deferredPrompt = event;
        buttons.forEach(button => (button.hidden = false));
    });

    buttons.forEach(button => {
        button.addEventListener('click', async () => {
            if (!deferredPrompt) return;

            // Hide first: the prompt can only be shown once per event, so
            // leaving the button live would offer a second press that does
            // nothing.
            hide();

            const prompt = deferredPrompt;
            deferredPrompt = null;

            prompt.prompt();

            const { outcome } = await prompt.userChoice;

            if (outcome === 'dismissed') writeDismissed();
        });
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        hide();
    });
};

registerServiceWorker();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireInstallPrompt);
} else {
    wireInstallPrompt();
}
