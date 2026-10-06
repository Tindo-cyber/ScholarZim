/**
 * Auto-dismisses a session flash two seconds after it appears, rather than
 * leaving it on screen until the reader clicks its own close button.
 *
 * Scoped to [data-sz-autodismiss], which components/flash-alerts.blade.php
 * puts on the success and error session flashes - the short "done" / "that
 * didn't work" feedback from an ordinary action. The validation summary does
 * not carry it: it lists fields the user still has to fix, so it stays until
 * they act.
 *
 * The timeout is strict. Hovering or focusing the alert used to postpone it
 * indefinitely; it no longer does - the close button is still there for
 * anyone who wants it gone sooner.
 *
 * Clicks the alert's existing [data-bs-dismiss="alert"] button rather than
 * calling bootstrap.Alert's own close() API - see navigation.js's
 * closeDrawer() for why: the vendor bundle (assets/bvite/js/bvite.js) throws
 * partway through loading and never assigns window.bootstrap, but Bootstrap's
 * delegated data-API listeners - wired to that same button - are registered
 * before that point and work regardless. If that click does not take the
 * alert away, it is removed directly, so it is never left on screen.
 */
(() => {
    'use strict';

    const AUTO_DISMISS_MS = 2000;

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.alert[data-sz-autodismiss]').forEach((alert) => {
            setTimeout(() => {
                if (!document.body.contains(alert)) return;

                const dismiss = alert.querySelector('[data-bs-dismiss="alert"]');

                if (dismiss) dismiss.click();

                // Bootstrap's fade-out takes ~150ms. Anything still attached
                // after that (no button, or no data-API listener) is removed
                // directly.
                setTimeout(() => {
                    if (document.body.contains(alert)) alert.remove();
                }, dismiss ? 500 : 0);
            }, AUTO_DISMISS_MS);
        });
    });
})();
