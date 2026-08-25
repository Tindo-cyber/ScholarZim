document.addEventListener('DOMContentLoaded', function () {
    var statusField = document.getElementById('field-status');
    var interviewWrapper = document.getElementById('interview-at-field');

    if (!statusField || !interviewWrapper) {
        return;
    }

    function sync() {
        interviewWrapper.style.display = statusField.value === 'INTERVIEW' ? '' : 'none';
    }

    statusField.addEventListener('change', sync);
    sync();
});
