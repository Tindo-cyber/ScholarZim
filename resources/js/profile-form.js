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
        HONOURS: 'POSTGRADUATE',
        POSTGRADUATE: 'POSTGRADUATE',
        MASTERS: 'POSTGRADUATE',
        PHD: 'POSTGRADUATE',
    };

    document.addEventListener('DOMContentLoaded', () => {
        const select = document.getElementById('sz-education-level');
        if (!select) return;

        const showFor = document.querySelectorAll('[data-sz-tier]');
        const hideFor = document.querySelectorAll('[data-sz-tier-hide]');

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
        };

        select.addEventListener('change', sync);
        sync();
    });
})();
