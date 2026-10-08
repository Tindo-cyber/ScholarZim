/**
 * The listing form follows the level it is for.
 *
 * "Who is this scholarship for?" is the first question, and the answer decides
 * which of the others make sense: a Form 1 bursary has no field of study or
 * A-Level points, a PhD has no school subjects. This hides what the chosen level
 * does not use, and clears it as it goes, so a stale value cannot ride along.
 *
 * WHICH LEVEL USES WHAT IS NOT DECIDED HERE. The page carries the answer as JSON
 * (#level-capabilities), built by the same PHP the server uses to validate and to
 * clear fields, so this script and the server cannot disagree about a level. If
 * the JSON is missing the script does nothing and the whole form stays visible.
 *
 * It is progressive enhancement and nothing more. With JavaScript off every field
 * is in the page and the form submits as it always did; the server then reports
 * any combination that cannot be true instead of quietly tidying it. Once this
 * script is running it adds a hidden `level_driven` field, which is the server's
 * cue that values in fields the level does not use are stale and may be dropped.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var select = document.getElementById('field-education_level');
        var dataEl = document.getElementById('level-capabilities');

        if (!select || !dataEl) return;

        var form = select.closest('form');
        var capabilities;

        try {
            capabilities = JSON.parse(dataEl.textContent || '{}');
        } catch (e) {
            return;
        }

        if (!form || !capabilities['']) return;

        var marker = document.createElement('input');
        marker.type = 'hidden';
        marker.name = 'level_driven';
        marker.value = '1';
        form.appendChild(marker);

        var sections = Array.prototype.slice.call(form.querySelectorAll('[data-level-needs]'));

        function clear(section) {
            section.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = false;
                } else if (el.tagName === 'SELECT') {
                    el.selectedIndex = 0;
                } else {
                    el.value = '';
                }
            });

            // Required subjects are rows, not fields: removing them is what clears them.
            section.querySelectorAll('.subject-requirement-row').forEach(function (row) {
                row.remove();
            });
        }

        function apply() {
            var uses = capabilities[select.value] || capabilities[''];

            sections.forEach(function (section) {
                var needed = uses[section.getAttribute('data-level-needs')] !== false;

                if (needed) {
                    section.hidden = false;

                    // Only re-enable what this script disabled; the subject grid
                    // disables its own empty selects and must keep doing so.
                    section.querySelectorAll('[data-level-disabled]').forEach(function (el) {
                        el.disabled = false;
                        el.removeAttribute('data-level-disabled');
                    });

                    return;
                }

                if (!section.hidden) clear(section);

                section.hidden = true;

                // A disabled control is not submitted, so nothing hidden is sent.
                section.querySelectorAll('input, select, textarea').forEach(function (el) {
                    if (!el.disabled) {
                        el.disabled = true;
                        el.setAttribute('data-level-disabled', '1');
                    }
                });
            });
        }

        select.addEventListener('change', apply);
        apply();
    });
})();
