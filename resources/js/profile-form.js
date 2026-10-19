/**
 * Progressive disclosure on the applicant profile form.
 *
 * Sections are tagged data-sz-tier="TIER[,TIER...]" (shown only for those
 * tiers) or data-sz-tier-hide="TIER[,TIER...]" (hidden for those tiers, shown
 * for everything else). The tier for the selected education level is looked
 * up from TIER_BY_LEVEL below, which mirrors App\Support\EducationLevel's own
 * tier table - kept in sync by hand rather than fetched, because it is four
 * short entries that essentially never change.
 *
 * This never gates what the server accepts. It only avoids showing an
 * applicant a guardian section, a field-of-study select, or a results-vs-
 * transcript question that does not apply to the level they just picked - the
 * server-side validation and the ScholarFit dimensions are the actual rules;
 * this is the form matching them.
 */
(() => {
    'use strict';

    const TIER_BY_LEVEL = {
        PRIMARY: 'PRIMARY',
        O_LEVEL: 'SECONDARY',
        A_LEVEL: 'SECONDARY',
        CERTIFICATE: 'TERTIARY',
        DIPLOMA: 'TERTIARY',
        UNDERGRADUATE: 'TERTIARY',
        POSTGRADUATE: 'POSTGRADUATE',
        MASTERS: 'POSTGRADUATE',
        PHD: 'POSTGRADUATE',
    };

    document.addEventListener('DOMContentLoaded', () => {
        const select = document.getElementById('sz-education-level');
        if (!select) return;

        const showFor = document.querySelectorAll('[data-sz-tier]');
        const hideFor = document.querySelectorAll('[data-sz-tier-hide]');
        // By level rather than tier: a Certificate and a Diploma share the tertiary tier with a degree but have no degree
        // classification, and ask for no academic results (their documents are the evidence).
        const levelHide = document.querySelectorAll('[data-sz-level-hide]');
        const levelShow = document.querySelectorAll('[data-sz-level-show]');
        // The qualification choices in the academic-results table were
        // rendered server-side for whichever level this page loaded with
        // (see ApplicantProfile::selectableQualifications()) and do not
        // re-fetch live when this select changes - see the notice's own
        // comment in profile.blade.php for why that is a save-and-reopen
        // gap rather than something fixed here with a second, JS-side copy
        // of that selection rule.
        // Under 18 shows the guardian card; with no date of birth, a pupil at school is treated as under 18.
        const minorFor = document.querySelectorAll('[data-sz-minor]');
        const dob = document.querySelector('input[name="date_of_birth"]');
        const SCHOOL_LEVELS = ['PRIMARY', 'O_LEVEL', 'A_LEVEL'];
        const isMinor = () => {
            if (dob && dob.value) {
                const born = new Date(dob.value);
                if (!Number.isNaN(born.getTime())) {
                    const cutoff = new Date();
                    cutoff.setFullYear(cutoff.getFullYear() - 18);
                    return born > cutoff;
                }
            }
            return SCHOOL_LEVELS.includes(select.value);
        };
        const staleNotice = document.getElementById('academic-level-stale-notice');
        const initialLevel = select.value;

        // The qualifications offered in the results editor are those of the level chosen in the form - Primary's Grade 7,
        // O-Level's, A-Level's - plus, for the level the page loaded with, any lower level a scholarship asks for. One a row
        // has already selected is left alone: hiding it would not remove the result, only make the row look as if it had
        // lost it. Applies to rows added later too. (The server still holds the line for the level it saves.)
        const resultsList = document.getElementById('academic-results-list');
        const syncQualifications = () => {
            const level = select.value;

            document.querySelectorAll('#academic-results option[data-level]').forEach((option) => {
                const keep = option.selected
                    || level === ''
                    || option.dataset.level === level
                    || (level === initialLevel && option.dataset.offered === '1');
                option.hidden = !keep;
                option.disabled = !keep;
            });
        };
        if (resultsList) {
            new MutationObserver(syncQualifications).observe(resultsList, { childList: true });
        }

        // The field of study is a programme from the catalogue, at the level chosen in the form. The list is drawn in full and
        // narrowed here by level and by what is typed in the box above it; a programme of another level is dropped from the
        // choice, so a level change never leaves one behind that the server would refuse.
        const programmes = document.getElementById('current_programme_id');
        let programmeSearch = null;
        if (programmes) {
            programmeSearch = document.createElement('input');
            programmeSearch.type = 'search';
            programmeSearch.className = 'form-control form-control-sm mb-1';
            programmeSearch.placeholder = 'Search the list';
            programmeSearch.setAttribute('aria-label', 'Search the programmes');
            programmes.parentNode.insertBefore(programmeSearch, programmes);
            programmeSearch.addEventListener('input', () => syncProgrammes());
        }
        const syncProgrammes = () => {
            if (!programmes) return;

            const level = select.value;
            const term = (programmeSearch ? programmeSearch.value : '').toLowerCase().replace(/\s+/g, ' ').trim();

            Array.prototype.forEach.call(programmes.options, (option) => {
                if (!option.value) return;

                const wrongLevel = option.dataset.level !== level;
                const noMatch = term !== '' && (option.dataset.search || option.textContent.toLowerCase()).indexOf(term) === -1;

                if (wrongLevel && option.selected) programmes.value = '';

                option.hidden = wrongLevel || noMatch;
                option.disabled = wrongLevel;
            });
        };

        const sync = () => {
            const tier = TIER_BY_LEVEL[select.value] || null;

            showFor.forEach((el) => {
                const tiers = el.dataset.szTier.split(',');
                el.hidden = tier === null || !tiers.includes(tier);
            });

            hideFor.forEach((el) => {
                const tiers = el.dataset.szTierHide.split(',');
                el.hidden = tier !== null && tiers.includes(tier);
            });

            // The results section is shown for Primary, O-Level and A-Level, and for the other levels only when a scholarship
            // asks for results (the page says so with data-sz-keep).
            levelHide.forEach((el) => {
                el.hidden = el.dataset.szLevelHide.split(',').includes(select.value) && el.dataset.szKeep !== '1';
            });

            levelShow.forEach((el) => {
                el.hidden = !el.dataset.szLevelShow.split(',').includes(select.value);
            });

            minorFor.forEach((el) => {
                el.hidden = !isMinor();
            });

            syncQualifications();
            syncProgrammes();

            if (staleNotice) {
                staleNotice.classList.toggle('d-none', select.value === initialLevel);
            }
        };

        select.addEventListener('change', sync);
        if (dob) {
            dob.addEventListener('input', sync);
            dob.addEventListener('change', sync);
        }
        sync();
    });
})();
