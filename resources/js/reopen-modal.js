/**
 * Open again a dialog whose submit was refused.
 *
 * A dialog form posts, the server refuses it, and the page reloads with the
 * dialog closed - the errors are in the markup but nobody can see them. The
 * server marks the dialog that failed (data-sz-open-on-load, see
 * components/confirm-dialog.blade.php); this opens it.
 *
 * It opens the dialog by clicking a throwaway trigger rather than calling
 * window.bootstrap.Modal: the theme bundle is not guaranteed to expose
 * `bootstrap` globally (navigation.js says as much), but Bootstrap's delegated
 * data-API is always listening, and a click on a data-bs-toggle="modal" element
 * is the one entry point that is documented to work either way.
 */
(function () {
    'use strict';

    // After `load`, not DOMContentLoaded: Bootstrap's own script has to have
    // attached its delegated handler before the click below can mean anything.
    function loaded(fn) {
        if (document.readyState === 'complete') {
            fn();
        } else {
            window.addEventListener('load', fn);
        }
    }

    loaded(function () {
        var dialog = document.querySelector('.modal[data-sz-open-on-load]');
        if (!dialog || !dialog.id) return;

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.hidden = true;
        trigger.setAttribute('data-bs-toggle', 'modal');
        trigger.setAttribute('data-bs-target', '#' + dialog.id);
        document.body.appendChild(trigger);

        trigger.click();
        trigger.remove();
    });
})();
