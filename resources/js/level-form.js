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
                    var was = el.checked;

                    el.checked = false;

                    // A picker's list of what is chosen follows the boxes, so tell it.
                    if (was && el.type === 'checkbox') el.dispatchEvent(new Event('change', { bubbles: true }));
                } else if (el.tagName === 'SELECT' && el.multiple) {
                    // A multiple select has no "first option" to fall back to: clearing it means choosing nothing.
                    Array.prototype.forEach.call(el.options, function (option) {
                        option.selected = false;
                    });
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

        // The lowest maximum age anyone could meet at this level (the platform minimum, or the level's own): the input's
        // `min` and its hint follow it. The server enforces the same figure, so this is guidance, not the rule.
        var minAges = {};

        try {
            minAges = JSON.parse((document.getElementById('level-min-ages') || {}).textContent || '{}');
        } catch (e) {
            minAges = {};
        }

        function followAge() {
            var input = form.querySelector('[data-max-age]');
            var age = minAges[select.value];

            if (!input || typeof age !== 'number') return;

            input.min = age;

            var hint = document.getElementById(input.id + '-hint');

            if (hint) {
                hint.textContent = hint.textContent.replace(/^At least \d+/, 'At least ' + age);
            }
        }

        // Form 1, O-Level and A-Level are for study in Zimbabwe (the list is the server's own: #zimbabwe-only-levels). For those the
        // country field shows Zimbabwe and offers nothing else; for any other level every country is offered again and the
        // choice is left as it is - it is already Zimbabwe, never a foreign value carried over. The server enforces the rule
        // regardless: this only stops the form offering what would be refused.
        var zimbabweOnly = [];

        try {
            zimbabweOnly = JSON.parse((document.getElementById('zimbabwe-only-levels') || {}).textContent || '[]');
        } catch (e) {
            zimbabweOnly = [];
        }

        var country = form.querySelector('[name="country"]');
        var countryHint = country ? document.getElementById(country.id + '-hint') : null;
        var countryHintText = countryHint ? countryHint.textContent : '';

        function followCountry() {
            if (!country) return;

            var fixed = zimbabweOnly.indexOf(select.value) !== -1;

            Array.prototype.forEach.call(country.options, function (option) {
                option.disabled = fixed && option.value !== 'Zimbabwe';
                option.hidden = option.disabled;
            });

            if (fixed && country.value !== 'Zimbabwe') {
                country.value = 'Zimbabwe';

                // The open-to section follows the country, so tell it.
                country.dispatchEvent(new Event('change', { bubbles: true }));
            }

            country.setAttribute('data-country-fixed', fixed ? '1' : '0');

            if (countryHint) {
                countryHint.textContent = fixed ? (country.getAttribute('data-zimbabwe-only-hint') || countryHintText) : countryHintText;
            }
        }

        function apply() {
            var uses = capabilities[select.value] || capabilities[''];

            followAge();
            followCountry();

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
