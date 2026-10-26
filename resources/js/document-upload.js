/**
 * Uploading a profile document without leaving the page.
 *
 * The document forms are separate from the profile form, so a plain submit reloaded the whole page: whatever had been typed
 * into the profile but not yet saved was thrown away, and the page jumped back to the top. This sends the file in the
 * background and swaps in the refreshed documents card the server already renders (the same card a reload would show, with
 * its own success or error line), leaving the rest of the page - and every unsaved entry in it - exactly as it was.
 *
 * Nothing here decides anything. The upload still goes to the same route and passes the same checks; the card that comes
 * back is the server's. If anything is unexpected (a network failure, an expired session, a page that is not the profile)
 * the form is submitted the ordinary way, so it can only ever fall back to the old behaviour, never lose an upload.
 */
(function () {
    'use strict';

    var ACTION = /\/applicant\/profile\/documents\/[a-z0-9_]+$/;

    function isDocumentForm(form) {
        return form instanceof HTMLFormElement && ACTION.test(new URL(form.action, location.href).pathname);
    }

    function fallback(form) {
        form.dataset.szPlain = '1';
        form.submit();
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!isDocumentForm(form) || form.dataset.szPlain === '1' || !window.fetch || !window.DOMParser) return;

        var card = form.closest('.card[id]');
        var input = form.querySelector('input[type=file]');

        // Nothing chosen: let the browser's own "required" message speak.
        if (!card || !input || !input.files || input.files.length === 0) return;

        event.preventDefault();

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
        }).then(function (response) {
            if (!response.ok) throw new Error('status ' + response.status);

            // The upload redirects back to the profile; that page is what comes back.
            if (!/\/applicant\/profile\/?(\?|#|$)/.test(new URL(response.url).pathname + '')) throw new Error('unexpected page');

            return response.text();
        }).then(function (html) {
            var next = new DOMParser().parseFromString(html, 'text/html').getElementById(card.id);

            if (!next) throw new Error('card missing');

            // The server's own message for this upload (success, or why it was refused), shown inside the card.
            var flash = new DOMParser().parseFromString(html, 'text/html').querySelector('.alert[role=alert]');
            var text = flash ? flash.textContent.replace(/\s+/g, ' ').trim() : '';

            card.replaceWith(next);

            if (text) {
                var note = document.createElement('div');
                var failed = flash.classList.contains('alert-danger');

                note.className = 'alert small mb-3 alert-' + (failed ? 'danger' : 'success');
                note.setAttribute('role', 'status');
                note.textContent = text;
                (next.querySelector('.card-body') || next).prepend(note);
            }

            // Let the form re-apply which cards its education level shows.
            var level = document.getElementById('sz-education-level');
            if (level) level.dispatchEvent(new Event('change', { bubbles: true }));
        }).catch(function () {
            fallback(form);
        });
    });
})();
