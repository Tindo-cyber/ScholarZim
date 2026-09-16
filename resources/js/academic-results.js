/**
 * The applicant's structured academic results editor.
 *
 * Qualification drives subject and result together: both lists come from the
 * qualification rows themselves, delivered as JSON in the page, so the form can
 * never offer a subject or a grade the server would then refuse. That is the
 * point of the whole exercise - the applicant states facts the catalogue knows
 * about, rather than typing prose the engine has to parse.
 *
 * Points are shown but never entered. There is no input in the points column
 * and no `points` field in the submitted data; the server derives the value
 * from the grade. The figure rendered here is the same arithmetic run for the
 * applicant's benefit while they are still typing, and it is recomputed from
 * the catalogue rather than carried over from the server, so it cannot drift
 * into being a second opinion.
 *
 * Nothing here is a security control. The server validates every row against
 * the catalogue - qualification active, subject belonging to it, grade one the
 * board awards, no duplicate pair - and rejects anything else. This is the form
 * agreeing with those rules in advance so an applicant is not told off after
 * pressing Save.
 */
(() => {
    'use strict';

    const init = () => {
        const list = document.getElementById('academic-results-list');
        const addButton = document.getElementById('add-academic-result');
        const template = document.getElementById('academic-result-template');
        const catalogueEl = document.getElementById('academic-catalogue');
        if (!list || !addButton || !template || !catalogueEl) return;

        let catalogue = {};
        try {
            catalogue = JSON.parse(catalogueEl.textContent || '{}');
        } catch (e) {
            return;
        }

        const summary = document.getElementById('academic-points-summary');
        const duplicateWarning = document.getElementById('academic-duplicate-warning');
        let nextIndex = list.querySelectorAll('.academic-result-row').length;

        const entryFor = (qualificationId) =>
            catalogue[qualificationId] || { name: '', grades: [], subjects: [], points: {}, awardsPoints: false };

        const fill = (select, options, selected, blankLabel) => {
            select.innerHTML = '';

            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = blankLabel;
            select.appendChild(blank);

            options.forEach((option) => {
                const el = document.createElement('option');
                el.value = option.value;
                el.textContent = option.label;
                if (String(selected) === String(option.value)) el.selected = true;
                select.appendChild(el);
            });

            select.disabled = options.length === 0;
        };

        const subjectIn = (entry, subjectId) =>
            entry.subjects.find((s) => String(s.id) === String(subjectId)) || null;

        /**
         * The scale a row is graded on: the subject's own where it has one,
         * otherwise the qualification's. Only the Cambridge IGCSE 9-1
         * syllabuses carry their own, and the two scales are never merged -
         * a 9-1 syllabus offers 9..1 and an A*-G one offers A*..G, under the
         * same qualification.
         */
        const scaleFor = (entry, subjectId) => {
            const subject = subjectIn(entry, subjectId);

            if (subject && subject.grades) {
                return { grades: subject.grades, points: subject.points || {}, subject };
            }

            return { grades: entry.grades, points: entry.points || {}, subject };
        };

        const syncRow = (row) => {
            const qualification = row.querySelector('.qualification-select');
            const subject = row.querySelector('.subject-select');
            const grade = row.querySelector('.grade-select');
            const points = row.querySelector('.derived-points');
            if (!qualification || !subject || !grade) return;

            const entry = entryFor(qualification.value);

            fill(
                subject,
                entry.subjects.map((s) => ({ value: s.id, label: s.name })),
                subject.dataset.selected || subject.value,
                'Select subject',
            );

            const scale = scaleFor(entry, subject.value);

            fill(
                grade,
                scale.grades.map((g) => ({ value: g, label: g })),
                grade.dataset.selected || grade.value,
                'Select result',
            );

            // Cleared once the options exist, so a later re-sync - triggered by
            // changing the subject, say - reads what the applicant has actually
            // chosen rather than resetting to the value the page loaded with.
            delete subject.dataset.selected;
            delete grade.dataset.selected;

            if (points) {
                // A scale with no points shows a dash rather than a zero:
                // O-Level symbols, Cambridge grades and Grade 7 units are not
                // worth nothing, they are not counted in points at all, and a 0
                // here would read as the former.
                const value = entry.awardsPoints ? scale.points[grade.value] : undefined;
                points.textContent = value === undefined || value === null ? '—' : String(value);
            }
        };

        const refreshSummary = () => {
            const totals = new Map();
            const pairs = new Set();
            let duplicate = false;

            list.querySelectorAll('.academic-result-row').forEach((row) => {
                const qualification = row.querySelector('.qualification-select');
                const subject = row.querySelector('.subject-select');
                const grade = row.querySelector('.grade-select');
                if (!qualification || !subject || !grade || !qualification.value || !subject.value) return;

                const pair = `${qualification.value}:${subject.value}`;
                if (pairs.has(pair)) duplicate = true;
                pairs.add(pair);

                const entry = entryFor(qualification.value);
                if (!entry.awardsPoints) return;

                const value = scaleFor(entry, subject.value).points[grade.value];
                if (value === undefined || value === null) return;

                totals.set(entry.name, (totals.get(entry.name) || 0) + Number(value));
            });

            if (duplicateWarning) duplicateWarning.classList.toggle('d-none', !duplicate);

            if (summary) {
                // Totals are reported per qualification and never added
                // together. One combined number is what let a Cambridge grade
                // and a ZIMSEC grade be summed on to the same scale.
                const parts = [...totals.entries()].map(([name, total]) => `${name}: ${total} points`);
                summary.textContent = parts.length ? parts.join(' · ') : '';
            }
        };

        const attach = (row) => {
            const qualification = row.querySelector('.qualification-select');
            const grade = row.querySelector('.grade-select');
            const subject = row.querySelector('.subject-select');

            if (qualification) {
                qualification.addEventListener('change', () => {
                    // The stored selections belong to the previous
                    // qualification's subject and grade lists, so they are
                    // dropped rather than carried across boards.
                    if (subject) delete subject.dataset.selected;
                    if (grade) delete grade.dataset.selected;
                    syncRow(row);
                    refreshSummary();
                });
            }

            [grade, subject].forEach((select) => {
                if (!select) return;
                select.addEventListener('change', () => {
                    syncRow(row);
                    refreshSummary();
                });
            });

            const remove = row.querySelector('.remove-row');
            if (remove) {
                remove.addEventListener('click', () => {
                    row.remove();
                    refreshSummary();
                });
            }

            syncRow(row);
        };

        addButton.addEventListener('click', () => {
            const html = template.innerHTML.replace(/__IDX__/g, String(nextIndex));
            nextIndex += 1;

            const host = document.createElement('tbody');
            host.innerHTML = html.trim();
            const row = host.querySelector('.academic-result-row');
            if (!row) return;

            list.appendChild(row);
            attach(row);
            refreshSummary();
        });

        list.querySelectorAll('.academic-result-row').forEach(attach);
        refreshSummary();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
