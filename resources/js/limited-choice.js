/**
 * Pick-up-to-N checkbox lists (resources/views/components/limited-choice.blade.php).
 *
 * Progressive enhancement: with this script off the list is plain checkboxes and the server refuses more than N
 * (ApplicantProgrammes::save), so nothing here is the rule. What it adds is the running count, a search box,
 * and - once N are ticked - disabling the rest so a fourth can't be ticked by accident. Untick one and the rest
 * come back, so a choice can be swapped before saving. Disabled boxes are unticked, so they post nothing.
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
        document.querySelectorAll('[data-limited-choice]').forEach(function (block) {
            var max = parseInt(block.getAttribute('data-max'), 10);
            var noun = block.getAttribute('data-noun') || 'choices';
            var boxes = Array.prototype.slice.call(block.querySelectorAll('input[type="checkbox"]'));
            var counter = block.querySelector('[data-limited-count]');
            var list = block.querySelector('[data-limited-list]');
            if (!max || !boxes.length) return;

            function sync() {
                var ticked = boxes.filter(function (box) { return box.checked; }).length;

                boxes.forEach(function (box) {
                    box.disabled = !box.checked && ticked >= max;
                });

                if (counter) {
                    counter.textContent = ticked + ' of ' + max + ' ' + noun + ' chosen' + (ticked >= max ? ' - untick one to change it.' : '.');
                }
            }

            var search = document.createElement('input');
            search.type = 'search';
            search.className = 'form-control form-control-sm mb-1';
            search.placeholder = 'Search the list';
            search.setAttribute('aria-label', 'Search ' + noun);
            block.insertBefore(search, list);

            search.addEventListener('input', function () {
                var term = search.value.toLowerCase().replace(/\s+/g, ' ').trim();

                block.querySelectorAll('[data-limited-option]').forEach(function (row) {
                    row.hidden = term !== '' && (row.getAttribute('data-search') || row.textContent.toLowerCase()).indexOf(term) === -1;
                });

                block.querySelectorAll('[data-limited-group]').forEach(function (group) {
                    group.hidden = Array.prototype.every.call(group.querySelectorAll('[data-limited-option]'), function (row) { return row.hidden; });
                });
            });

            block.addEventListener('change', sync);
            sync();
        });
    });
})();
