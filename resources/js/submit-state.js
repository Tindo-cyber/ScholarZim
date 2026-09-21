/*
 * Busy state for <x-submit-button>.
 *
 * Delegated from the document, so it covers a button that arrives later - one
 * inside a modal, or a row added by academic-results.js - without re-binding.
 *
 * Deliberately not a loading simulation. The only thing that puts a button into
 * its busy state is the owning form's own `submit` event, which the browser
 * fires once it has accepted the submission and not before; a form that fails
 * constraint validation never fires it. Nothing here uses a timer, and nothing
 * clears the state on a schedule - the state ends when the next document
 * arrives, which is exactly when the work it represents is over.
 */
(function () {
    'use strict';

    var BUSY_CLASS = 'is-busy';

    function buttonsFor(form) {
        var own = Array.prototype.slice.call(form.querySelectorAll('[data-sz-submit]'));

        // A button can be bound to a form by id rather than by containment -
        // the admin moderation queue does exactly that, because a form inside a
        // form is dropped by the browser.
        if (form.id) {
            var bound = document.querySelectorAll('[data-sz-submit][form="' + form.id + '"]');
            Array.prototype.forEach.call(bound, function (button) {
                if (own.indexOf(button) === -1) {
                    own.push(button);
                }
            });
        }

        return own;
    }

    function markBusy(button) {
        button.classList.add(BUSY_CLASS);
        button.setAttribute('aria-busy', 'true');

        var spinner = button.querySelector('[data-sz-submit-spinner]');
        if (spinner) {
            spinner.classList.remove('d-none');
        }

        // An icon and a spinner side by side reads as two things happening.
        var icon = button.querySelector('[data-sz-submit-icon]');
        if (icon) {
            icon.classList.add('d-none');
        }

        var busyLabel = button.getAttribute('data-sz-busy-label');
        var label = button.querySelector('[data-sz-submit-label]');
        if (busyLabel && label) {
            label.textContent = busyLabel;
        }
    }

    function clearBusy(button) {
        button.classList.remove(BUSY_CLASS);
        button.removeAttribute('aria-busy');

        var spinner = button.querySelector('[data-sz-submit-spinner]');
        if (spinner) {
            spinner.classList.add('d-none');
        }

        var icon = button.querySelector('[data-sz-submit-icon]');
        if (icon) {
            icon.classList.remove('d-none');
        }
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var buttons = buttonsFor(form);
        if (!buttons.length) {
            return;
        }

        /*
         * Only the button that was pressed.
         *
         * A form can offer more than one submit - the provider's review panel
         * has Accept and Reject side by side, each carrying its own name and
         * value - and spinning both would say two things were happening.
         * event.submitter names the one that did it; where the browser does not
         * provide it, marking all of them is the safe fallback.
         */
        if (event.submitter && buttons.indexOf(event.submitter) !== -1) {
            buttons = [event.submitter];
        }

        // A second submit of a form already on its way is a double-press, and
        // the request it would make is a duplicate - a second application, a
        // second withdrawal. The first one is already in flight.
        if (form.dataset.szSubmitting === 'true') {
            event.preventDefault();
            return;
        }

        form.dataset.szSubmitting = 'true';
        buttons.forEach(markBusy);
    });

    /*
     * Coming back to this page with the browser's Back button restores it from
     * the page cache exactly as it was left - including a button still spinning
     * over a submission that finished long ago, and a form that now refuses to
     * submit again.
     */
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) {
            return;
        }

        document.querySelectorAll('form[data-sz-submitting="true"]').forEach(function (form) {
            delete form.dataset.szSubmitting;
        });

        document.querySelectorAll('[data-sz-submit]').forEach(clearBusy);
    });
})();
