/**
 * "Who is it open to?" follows the answers given in it.
 *
 *  - "All programmes at this level" hides the programme and field lists ([data-open-chosen]).
 *  - "Any institution" hides the institution list ([data-institutions-chosen]).
 *  - A country other than Zimbabwe hides the Zimbabwean programme and institution lists ([data-home-only])
 *    and shows what applies abroad ([data-abroad-only]): fields, and host universities in words.
 *
 * Hiding a list also clears it, so a choice nobody can see is never sent. The server applies the same
 * rules (ListingScopes::decision and ::save) and drops anything an answer overrides, so with JavaScript
 * off the form still works - it just shows everything.
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

    function clear(section) {
        section.querySelectorAll('select').forEach(function (select) {
            if (select.multiple) {
                Array.prototype.forEach.call(select.options, function (option) {
                    option.selected = false;
                });
            }
        });
        section.querySelectorAll('input[type="radio"]').forEach(function (radio) {
            radio.checked = false;
        });
        section.querySelectorAll('input[type="text"], input:not([type])').forEach(function (input) {
            input.value = '';
        });
    }

    function show(section, visible) {
        if (!visible && !section.hidden) {
            clear(section);
        }

        section.hidden = !visible;
    }

    ready(function () {
        var card = document.querySelector('[data-open-to]');

        if (!card) return;

        var form = card.closest('form');
        var country = form ? form.querySelector('[name="country"]') : null;

        function value(name) {
            var checked = card.querySelector('input[name="' + name + '"]:checked');

            return checked ? checked.value : null;
        }

        function apply() {
            var abroad = !!country && country.value !== '' && country.value !== 'Zimbabwe';

            card.querySelectorAll('[data-home-only]').forEach(function (section) {
                show(section, !abroad);
            });

            card.querySelectorAll('[data-abroad-only]').forEach(function (section) {
                show(section, abroad);
            });

            card.querySelectorAll('[data-open-chosen]').forEach(function (section) {
                show(section, value('open_to') !== 'all');
            });

            card.querySelectorAll('[data-institutions-chosen]').forEach(function (section) {
                show(section, value('institutions_open_to') !== 'any');
            });
        }

        card.addEventListener('change', function (event) {
            if (event.target && (event.target.name === 'open_to' || event.target.name === 'institutions_open_to')) {
                apply();
            }
        });

        // Choosing from a list answers the question it sits under.
        card.addEventListener('change', function (event) {
            var target = event.target;

            if (!target || target.tagName !== 'SELECT' || !target.multiple || target.selectedOptions.length === 0) return;

            var radio = target.name === 'scope_institutions[]'
                ? card.querySelector('#institutions-chosen')
                : (target.name === 'scope_programmes[]' || target.name === 'scope_fields[]' ? card.querySelector('#open-to-chosen') : null);

            if (radio && !radio.checked) {
                radio.checked = true;
            }
        });

        if (country) {
            country.addEventListener('change', apply);
        }
    });
})();
