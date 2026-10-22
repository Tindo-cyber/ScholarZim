/**
 * "Who is it open to?" follows the answers given in it.
 *
 *  - The answer to "how widely does it apply?" decides which list shows: "Specific programmes" the programme list
 *    ([data-open-programmes]), "An entire field of study" the field list ([data-open-fields]), "All programmes at this
 *    level" neither, and a listing saved with both keeps both. Until an answer is chosen neither shows.
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
        // Unticking is announced so a picker's list of chosen items follows.
        section.querySelectorAll('input[type="checkbox"]:checked').forEach(function (box) {
            box.checked = false;
            box.dispatchEvent(new Event('change', { bubbles: true }));
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

            card.querySelectorAll('[data-home-only]:not([data-open-programmes])').forEach(function (section) {
                show(section, !abroad);
            });

            card.querySelectorAll('[data-abroad-only]').forEach(function (section) {
                show(section, abroad);
            });

            var mode = value('open_to');

            card.querySelectorAll('[data-open-programmes]').forEach(function (section) {
                show(section, !abroad && (mode === 'programmes' || mode === 'chosen'));
            });

            card.querySelectorAll('[data-open-fields]').forEach(function (section) {
                show(section, mode === 'fields' || mode === 'chosen');
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

        // Ticking an institution answers "which institutions?" with "only the ones I choose".
        card.addEventListener('change', function (event) {
            var target = event.target;

            if (!target || target.type !== 'checkbox' || !target.checked || !target.closest('#scope-institutions')) return;

            var radio = card.querySelector('#institutions-chosen');

            if (radio && !radio.checked) {
                radio.checked = true;
            }
        });

        // The engineering note shows while "Engineering" is ticked without the broad field that holds civil and mining.
        var hint = card.querySelector('[data-engineering-hint]');

        function syncHint() {
            if (!hint) return;

            var narrow = card.querySelector('#scope-fields-' + hint.getAttribute('data-narrow'));
            var broad = card.querySelector('#scope-fields-' + hint.getAttribute('data-broad'));

            hint.hidden = !(narrow && narrow.checked && !(broad && broad.checked));
        }

        card.addEventListener('change', function (event) {
            if (event.target && event.target.closest && event.target.closest('#scope-fields')) syncHint();
        });

        if (country) {
            country.addEventListener('change', apply);
        }
    });
})();
