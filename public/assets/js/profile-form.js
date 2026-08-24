(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const select = document.getElementById('field-education_level');
        const resultsField = document.getElementById('sz-academic-results-field');
        if (!select || !resultsField) return;

        const schoolLevels = JSON.parse(resultsField.dataset.schoolLevels || '[]');

        const sync = () => {
            resultsField.style.display = schoolLevels.includes(select.value) ? '' : 'none';
        };

        select.addEventListener('change', sync);
        sync();
    });
})();
