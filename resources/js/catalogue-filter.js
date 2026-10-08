/**
 * A search box above each programme, field and institution list, and the level filter on the
 * programme list.
 *
 * Progressive enhancement over plain multiple <select>s: with this script off, every option is
 * in the page and the form submits as it always did. The server checks everything again
 * (ListingScopes::problems), so hiding an option here is a convenience, never the rule.
 *
 *   [data-catalogue-filter]       a block holding one <select multiple>; a search box is added
 *   option[data-search]           lower-case text the box matches against (name and synonyms)
 *   select[data-filter-level=ID]  the id of the level <select>; options whose data-level differs
 *                                 are hidden, and unselected if they were chosen
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
        return String(text || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    ready(function () {
        document.querySelectorAll('[data-catalogue-filter]').forEach(function (block) {
            var select = block.querySelector('select[multiple]');
            if (!select) return;

            var search = document.createElement('input');
            search.type = 'search';
            search.className = 'form-control form-control-sm mb-1';
            search.placeholder = 'Search the list';
            search.setAttribute('aria-label', 'Search ' + (block.querySelector('label') || { textContent: 'the list' }).textContent.toLowerCase());
            select.parentNode.insertBefore(search, select);

            var levelSelect = select.getAttribute('data-filter-level')
                ? document.getElementById(select.getAttribute('data-filter-level'))
                : null;

            function apply() {
                var term = normalise(search.value);
                var level = levelSelect ? levelSelect.value : '';

                Array.prototype.forEach.call(select.options, function (option) {
                    var wrongLevel = level !== '' && option.getAttribute('data-level') && option.getAttribute('data-level') !== level;
                    var noMatch = term !== '' && normalise(option.getAttribute('data-search') || option.textContent).indexOf(term) === -1;

                    if (wrongLevel) option.selected = false;

                    option.hidden = wrongLevel || noMatch;
                });

                Array.prototype.forEach.call(select.querySelectorAll('optgroup'), function (group) {
                    group.hidden = Array.prototype.every.call(group.querySelectorAll('option'), function (option) {
                        return option.hidden;
                    });
                });
            }

            search.addEventListener('input', apply);
            if (levelSelect) levelSelect.addEventListener('change', apply);
            apply();
        });
    });
})();
