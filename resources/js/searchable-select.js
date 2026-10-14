/**
 * A search box above a long single-choice <select>, such as the country list.
 *
 * Progressive enhancement only: with this script off, the plain <select> is
 * there with every option, and typing a name while it has focus already jumps
 * to it. The server validates the chosen value either way
 * (ProviderRegistrationDetails::rules), so filtering here is a convenience.
 *
 *   [data-searchable-select]   a block holding one <select>
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

    function normalise(text) {
        // Accents are ignored, so "cote" finds Côte d'Ivoire and "aland" Åland.
        return String(text || '')
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .trim();
    }

    ready(function () {
        document.querySelectorAll('[data-searchable-select]').forEach(function (block) {
            var select = block.querySelector('select:not([multiple])');
            if (!select) return;

            var label = block.querySelector('label');
            var search = document.createElement('input');
            search.type = 'search';
            search.className = 'form-control form-control-sm mb-1';
            search.placeholder = 'Type to filter the list';
            search.setAttribute('aria-label', 'Filter ' + (label ? label.textContent.replace('*', '').trim().toLowerCase() : 'the list'));
            search.setAttribute('aria-controls', select.id);
            search.autocomplete = 'off';
            select.parentNode.insertBefore(search, select);

            search.addEventListener('input', function () {
                var term = normalise(search.value);

                Array.prototype.forEach.call(select.options, function (option) {
                    if (option.value === '') return;

                    var match = term === '' || normalise(option.textContent).indexOf(term) !== -1;
                    // Never hide the current choice, and never choose for the
                    // organisation: the country is theirs to pick explicitly.
                    option.hidden = !match && !option.selected;
                });
            });
        });
    });
})();
