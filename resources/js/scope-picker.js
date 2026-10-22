/**
 * The provider's programme, field and institution pickers (resources/views/components/scope-picker.blade.php).
 *
 * Progressive enhancement over a plain group of checkboxes: with this script off every option is in the page and the form
 * posts as it always did. The server checks every id again (ListingScopes::problems), so hiding or unticking one here is
 * a convenience, never the rule.
 *
 *   [data-picker-search]            hidden until this runs; filters options by their data-search text
 *   [data-picker-chosen]            what is ticked, each with a button to remove it
 *   [data-filter-level=ID]          the id of the level <select>: options of another level (data-level) are removed, and
 *                                   unticked if they were chosen, with a note saying so
 *   <template data-picker-reserve>  the groups of the levels not in use. They are inert (no boxes, no tab stops, nothing
 *                                   posted) until the level they belong to is chosen, or, with no level chosen, until
 *                                   something is typed in the search; this keeps a catalogue of 1,400 programmes out of
 *                                   the page's live DOM.
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
        document.querySelectorAll('[data-scope-picker]').forEach(function (picker) {
            var list = picker.querySelector('[data-picker-list]');
            var reserve = picker.querySelector('template[data-picker-reserve]');
            var prompt = picker.querySelector('[data-picker-prompt]');
            var search = picker.querySelector('[data-picker-search]');
            var chosen = picker.querySelector('[data-picker-chosen]');
            var chips = picker.querySelector('[data-picker-chips]');
            var count = picker.querySelector('[data-picker-count]');
            var note = picker.querySelector('[data-picker-note]');
            var empty = picker.querySelector('[data-picker-empty]');
            var levelId = picker.getAttribute('data-filter-level');
            var levelSelect = levelId ? document.getElementById(levelId) : null;

            // Always read from the page: groups move between the list and the reserve.
            function liveOptions() { return Array.prototype.slice.call(list.querySelectorAll('[data-picker-option]')); }
            function liveGroups() { return Array.prototype.slice.call(list.querySelectorAll('[data-picker-group]')); }

            function order(group) { return parseInt(group.getAttribute('data-order') || '0', 10); }

            function hasTicked(group) { return !!group.querySelector('input[type="checkbox"]:checked'); }

            // Puts a group back into the list in its original place.
            function show(group) {
                var next = liveGroups().filter(function (other) { return order(other) > order(group); })[0];

                list.insertBefore(group, next || empty || null);
            }

            function box(option) {
                return option.querySelector('input[type="checkbox"]');
            }

            // A programme's chip names its field too, so two with similar names can be told apart.
            function nameOf(option) {
                var group = option.closest('[data-picker-group]');
                var field = group ? group.getAttribute('data-field') : '';

                return option.querySelector('[data-picker-name]').textContent.trim() + (field ? ' (' + field + ')' : '');
            }

            function refreshChips() {
                chips.textContent = '';

                var ticked = liveOptions().filter(function (option) { return box(option).checked; });

                ticked.forEach(function (option) {
                    var item = document.createElement('li');
                    var button = document.createElement('button');
                    var name = nameOf(option);

                    item.className = 'list-inline-item mb-1';
                    button.type = 'button';
                    button.className = 'btn btn-sm btn-outline-secondary';
                    button.setAttribute('aria-label', 'Remove ' + name);
                    button.textContent = name + ' ×';
                    button.addEventListener('click', function () {
                        box(option).checked = false;
                        box(option).dispatchEvent(new Event('change', { bubbles: true }));
                    });
                    item.appendChild(button);
                    chips.appendChild(item);
                });

                count.textContent = String(ticked.length);
                chosen.hidden = ticked.length === 0;
            }

            function apply(announce) {
                var term = normalise(search.value);
                var level = levelSelect ? levelSelect.value : '';
                var removed = 0;

                if (reserve) {
                    // Which groups belong in the list now: the level chosen; with none chosen, everything once a name is
                    // being searched for, and otherwise only the groups that already hold a ticked choice.
                    var wanted = function (group) {
                        if (level !== '') return group.getAttribute('data-level') === level;

                        return term.length >= 2 || hasTicked(group);
                    };

                    liveGroups().forEach(function (group) {
                        if (wanted(group)) return;

                        group.querySelectorAll('input[type="checkbox"]:checked').forEach(function (input) {
                            input.checked = false;
                            removed++;
                        });
                        reserve.content.appendChild(group);
                    });

                    Array.prototype.slice.call(reserve.content.querySelectorAll('[data-picker-group]')).forEach(function (group) {
                        if (wanted(group)) show(group);
                    });
                }

                liveOptions().forEach(function (option) {
                    var optionLevel = option.getAttribute('data-level');
                    var wrongLevel = level !== '' && optionLevel && optionLevel !== level;
                    var noMatch = term !== '' && normalise(option.getAttribute('data-search')).indexOf(term) === -1;

                    if (wrongLevel && box(option).checked) {
                        box(option).checked = false;
                        removed++;
                    }

                    option.hidden = wrongLevel || noMatch;
                });

                var visible = 0;

                liveGroups().forEach(function (group) {
                    var any = Array.prototype.some.call(group.querySelectorAll('[data-picker-option]'), function (option) {
                        return !option.hidden;
                    });

                    group.hidden = !any;
                    if (any) visible++;
                });

                var waiting = !!reserve && level === '' && term.length < 2 && visible === 0;

                if (prompt) prompt.hidden = !waiting;
                if (empty) empty.hidden = visible > 0 || waiting;

                if (note) {
                    note.hidden = !(announce && removed > 0);
                    note.textContent = removed > 0
                        ? removed + (removed === 1 ? ' choice was' : ' choices were') + ' removed because ' + (removed === 1 ? 'it is' : 'they are') + ' not at the level chosen above.'
                        : '';
                }

                refreshChips();
            }

            search.hidden = false;
            search.addEventListener('input', function () { apply(false); });
            picker.addEventListener('change', function (event) {
                if (event.target && event.target.type === 'checkbox') refreshChips();
            });
            if (levelSelect) levelSelect.addEventListener('change', function () { apply(true); });
            apply(false);
        });
    });
})();
