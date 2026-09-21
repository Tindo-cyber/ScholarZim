/**
 * A listing's subject requirements: the grid a provider uses to say "this
 * scholarship needs Mathematics at grade C or better".
 *
 * Qualification drives subject and grade together. Both lists come from the
 * qualification rows themselves, delivered as JSON in the page, so the form
 * cannot offer a grade the server would reject - the grades a board awards and
 * the grades this picker shows are the same list.
 *
 * Nothing here is a business rule. The server validates every submitted row
 * against the catalogue, and this is the form agreeing with those rules in
 * advance rather than a second opinion about them. Moving the code has not
 * changed a line of what it does; see the note below on why it moved.
 *
 * WHY THIS IS NOT INSIDE THE BLADE VIEW ANY MORE
 *
 * It used to be an inline <script> at the bottom of
 * resources/views/opportunities/partials/award-fields.blade.php, and the
 * application's Content-Security-Policy says `script-src 'self'`. An inline
 * script is not 'self'; it needs 'unsafe-inline', a nonce, or a hash. So the
 * browser refused to execute it, "Add a required subject" did nothing at all,
 * and a provider could not attach a subject requirement to a listing on either
 * the create or the edit form.
 *
 * It failed silently in the only way that matters: the page rendered, the
 * button was there, the button was clickable, and the console message was one
 * line in a wall of CSP noise from the vendor theme's CDN font references.
 *
 * Bundled through Vite, this is served from the application's own origin, so
 * `script-src 'self'` covers it and the policy does not have to move. Which is
 * the right way round: the policy was correct and the markup was wrong.
 *
 * The <script type="application/json"> catalogue in that view stays where it
 * is. A data block is not executed, so CSP has no opinion about it.
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
        var listEl = document.getElementById('subject-requirements-list');
        var addButton = document.getElementById('add-subject-requirement');
        var templateEl = document.getElementById('subject-requirement-template');
        var catalogueEl = document.getElementById('subject-requirement-catalogue');
        if (!listEl || !addButton || !templateEl || !catalogueEl) return;

        var catalogue = JSON.parse(catalogueEl.textContent || '{}');
        var nextIdx = listEl.querySelectorAll('.subject-requirement-row').length;

        function fill(select, options, selected, blankLabel) {
            select.innerHTML = '';
            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = blankLabel;
            select.appendChild(blank);

            options.forEach(function (option) {
                var el = document.createElement('option');
                el.value = option.value;
                el.textContent = option.label;
                if (String(selected) === String(option.value)) el.selected = true;
                select.appendChild(el);
            });

            select.disabled = options.length === 0;
        }

        function sync(row) {
            var qualSelect = row.querySelector('.qualification-select');
            var subjectSelect = row.querySelector('.subject-select');
            var gradeSelect = row.querySelector('.grade-select');
            if (!qualSelect || !subjectSelect || !gradeSelect) return;

            var entry = catalogue[qualSelect.value] || { subjects: [], grades: [] };

            fill(
                subjectSelect,
                entry.subjects.map(function (s) { return { value: s.id, label: s.name }; }),
                subjectSelect.dataset.selected || subjectSelect.value,
                'Select subject'
            );

            // The subject's own scale where it has one - the Cambridge
            // IGCSE 9-1 syllabuses - otherwise the qualification's. The
            // two are never offered together: a 9-1 syllabus cannot be
            // given an A*-G bar, because the scales do not convert.
            var subject = entry.subjects.filter(function (s) {
                return String(s.id) === String(subjectSelect.value);
            })[0];
            var grades = (subject && subject.grades) ? subject.grades : entry.grades;

            fill(
                gradeSelect,
                grades.map(function (g) { return { value: g, label: g }; }),
                gradeSelect.dataset.selected || gradeSelect.value,
                'Any grade'
            );

            // Cleared once populated, so re-syncing after a subject
            // change reads the provider's actual choice rather than
            // resetting to whatever the page loaded with.
            delete subjectSelect.dataset.selected;
            delete gradeSelect.dataset.selected;

            // A blank grade is a valid choice - the subject is required
            // but no bar is set on it - so the grade select stays usable
            // even when no grades are configured.
            gradeSelect.disabled = grades.length === 0;
        }

        function attach(row) {
            var qualSelect = row.querySelector('.qualification-select');
            if (qualSelect) {
                qualSelect.addEventListener('change', function () {
                    var subjectSelect = row.querySelector('.subject-select');
                    var gradeSelect = row.querySelector('.grade-select');
                    if (subjectSelect) delete subjectSelect.dataset.selected;
                    if (gradeSelect) delete gradeSelect.dataset.selected;
                    sync(row);
                });
            }

            // Changing the subject can change the scale it is graded
            // on, so the grade list is rebuilt with it.
            var subjectSelect = row.querySelector('.subject-select');
            if (subjectSelect) {
                subjectSelect.addEventListener('change', function () { sync(row); });
            }

            var removeButton = row.querySelector('.remove-row');
            if (removeButton) {
                removeButton.addEventListener('click', function () { row.remove(); });
            }

            sync(row);
        }

        addButton.addEventListener('click', function () {
            var html = templateEl.innerHTML.replace(/__IDX__/g, String(nextIdx++));
            var host = document.createElement('tbody');
            host.innerHTML = html.trim();
            var row = host.querySelector('.subject-requirement-row');
            if (!row) return;
            listEl.appendChild(row);
            attach(row);
        });

        listEl.querySelectorAll('.subject-requirement-row').forEach(attach);
    });
})();
