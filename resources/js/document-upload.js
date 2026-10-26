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
 *
 * That ordinary way reloads the page, and a reload shows what is SAVED, not what was typed. So before any upload starts, what
 * is typed into the profile form is kept in this tab's sessionStorage; when the background upload works it is discarded, and
 * when the page has to reload it is put back (once, within a few minutes) so nothing the applicant entered is lost. Nothing
 * is sent to the server and nothing is saved to the profile: the values come back as unsaved entries, ready to be saved.
 */
(function () {
    'use strict';

    var ACTION = /\/applicant\/profile\/documents\/[a-z0-9_]+$/;

    function isDocumentForm(form) {
        return form instanceof HTMLFormElement && ACTION.test(new URL(form.action, location.href).pathname);
    }

    var DRAFT_KEY = 'sz-profile-draft';
    var DRAFT_MAX_AGE_MS = 10 * 60 * 1000;

    // Rows the academic-results editor adds are built by script and are not part of this; everything else on the form is.
    function draftable(el) {
        return el.name && el.name !== '_token' && el.name !== '_method' && !el.disabled
            && el.type !== 'file' && el.type !== 'password' && el.type !== 'submit' && el.type !== 'button'
            && el.name.indexOf('academic_subject_results') !== 0;
    }

    function saveDraft() {
        var profile = document.getElementById('sz-profile-form');

        if (!profile) return;

        var fields = [];

        Array.prototype.forEach.call(profile.elements, function (el) {
            if (!draftable(el)) return;

            fields.push({ name: el.name, type: el.type, value: el.value, checked: el.checked });
        });

        try {
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ at: Date.now(), path: location.pathname, fields: fields }));
        } catch (e) { /* storage unavailable: the upload still works, only the safety net is missing */ }
    }

    function clearDraft() {
        try { sessionStorage.removeItem(DRAFT_KEY); } catch (e) { /* nothing to clear */ }
    }

    function restoreDraft() {
        var profile = document.getElementById('sz-profile-form');
        var draft = null;

        try {
            draft = JSON.parse(sessionStorage.getItem(DRAFT_KEY) || 'null');
            sessionStorage.removeItem(DRAFT_KEY); // used once
        } catch (e) {
            return;
        }

        if (!profile || !draft || draft.path !== location.pathname || Date.now() - draft.at > DRAFT_MAX_AGE_MS) return;

        draft.fields.forEach(function (saved) {
            Array.prototype.forEach.call(profile.elements, function (el) {
                if (el.name !== saved.name || !draftable(el)) return;

                if (el.type === 'radio') {
                    el.checked = el.value === saved.value && saved.checked;
                } else if (el.type === 'checkbox') {
                    el.checked = saved.checked;
                } else {
                    el.value = saved.value;
                }

                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        // Let the form re-apply which cards its education level shows.
        var level = document.getElementById('sz-education-level');
        if (level) level.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function fallback(form) {
        // The page is about to reload: the draft is already kept (saved before the upload began) and is restored afterwards.
        form.dataset.szPlain = '1';
        form.submit();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreDraft);
    } else {
        restoreDraft();
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!isDocumentForm(form) || form.dataset.szPlain === '1') return;

        // Kept before anything else can go wrong: if the page reloads for any reason - this script unable to run the upload
        // included - what was typed comes back with it.
        saveDraft();

        if (!window.fetch || !window.DOMParser) return;

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

            // The page was not reloaded, so nothing needs putting back.
            clearDraft();

            // Let the form re-apply which cards its education level shows.
            var level = document.getElementById('sz-education-level');
            if (level) level.dispatchEvent(new Event('change', { bubbles: true }));
        }).catch(function () {
            fallback(form);
        });
    });
})();
