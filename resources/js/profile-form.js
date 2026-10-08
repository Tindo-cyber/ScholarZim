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

            minorFor.forEach((el) => {
                el.hidden = !isMinor();
            });

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
