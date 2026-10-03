/**
 * Auto-dismisses a success flash a few seconds after it appears, rather than
 * leaving it on screen until the reader clicks its own close button.
 *
 * Scoped to .alert-success only: an error or the validation summary is
 * deliberately left alone, since something worth telling someone about going
 * wrong is worth letting them read on their own time, not racing a timer.
 *
 * Clicks the alert's existing [data-bs-dismiss="alert"] button rather than
 * calling bootstrap.Alert's own close() API - see navigation.js's
 * closeDrawer() for why: the vendor bundle (assets/bvite/js/bvite.js) throws
 * partway through loading and never assigns window.bootstrap, but Bootstrap's
 * delegated data-API listeners - wired to that same button - are registered
 * before that point and work regardless.
 */
(() => {
    'use strict';

    const AUTO_DISMISS_MS = 6000;

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.alert-success.alert-dismissible').forEach((alert) => {
            const dismiss = alert.querySelector('[data-bs-dismiss="alert"]');

            if (!dismiss) return;

            setTimeout(() => {
                // Only close what is still there and not currently being
                // read - a reader with the pointer over it, or who has
                // tabbed into it, gets to finish rather than losing it
                // mid-read. They can still dismiss it themselves.
                if (!document.body.contains(alert) || alert.matches(':hover') || alert.contains(document.activeElement)) {
                    return;
                }

                dismiss.click();
            }, AUTO_DISMISS_MS);
        });
    });
})();
