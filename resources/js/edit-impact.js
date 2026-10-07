/**
 * The "what will saving this do?" notice on a provider's edit page.
 *
 * Whether an edit takes a live listing offline is decided by the server
 * (OpportunityLifecycle's list of material fields, plus subject requirements and
 * a shortened deadline). This script does not know that rule and does not copy
 * it: it sends the form as it stands to the server, which runs the very check
 * saving will run, and shows the answer. A second copy of the rule in JavaScript
 * would be right on the day it was written and wrong the first time the server's
 * list changed.
 *
 * Nothing here is required. With no script the page shows the server's first
 * answer (rendered into the notice) and the form saves exactly as before.
 */
(function () {
    'use strict';

    var DELAY = 350;

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var notice = document.getElementById('edit-impact');
        if (!notice) return;

        var form = notice.closest('form');
        var url = notice.getAttribute('data-url');
        if (!form || !url) return;

        var headline = notice.querySelector('[data-impact-headline]');
        var detail = notice.querySelector('[data-impact-detail]');
        var button = form.querySelector('[data-sz-submit]');
        var buttonLabel = button ? button.querySelector('[data-sz-submit-label]') : null;

        var timer = null;
        var sequence = 0;

        function show(answer) {
            notice.className = 'alert alert-' + answer.tone + ' mb-4';
            if (headline) headline.textContent = answer.headline;
            if (detail) detail.textContent = answer.detail;

            // Never rewrite the label while the button is showing its busy text.
            if (buttonLabel && button && !button.classList.contains('is-busy')) {
                buttonLabel.textContent = answer.button;
            }
        }

        function refresh() {
            var mine = ++sequence;

            // The edit form carries a hidden _method=PUT so that saving it is a PUT.
            // Sent along, Laravel would treat this POST as a PUT too and answer
            // 405, so it is left out: this request only asks, it does not save.
            var body = new FormData(form);
            body.delete('_method');

            fetch(url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (answer) {
                    // A slower, older answer must not overwrite a newer one.
                    if (answer && mine === sequence) show(answer);
                })
                .catch(function () {
                    // The notice is advice. If it cannot be fetched the form still
                    // saves, and the server still decides; keep what is showing.
                });
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(refresh, DELAY);
        }

        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);

        // Adding or removing a subject row changes the form without an input event.
        form.addEventListener('click', function (event) {
            var target = event.target;
            if (target && target.closest && target.closest('#add-subject-requirement, .remove-row')) {
                schedule();
            }
        });
    });
})();
