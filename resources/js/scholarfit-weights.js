/**
 * Keeps the ScholarFit weight sliders, the number inputs, and the running total
 * in step, and blocks a save that does not add up to 100.
 *
 * The same rule is enforced in SettingsService, which is what actually protects
 * the data; this only saves the administrator a round trip to find that out.
 */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('scholarfit-weights-form');

    if (!form) {
        return;
    }

    var inputs = Array.prototype.slice.call(form.querySelectorAll('[data-weight-input]'));
    var totalEl = form.querySelector('[data-weights-total]');
    var barEl = form.querySelector('[data-weights-bar]');
    var messageEl = form.querySelector('[data-weights-message]');
    var submitButton = form.querySelector('button[type="submit"]');

    function total() {
        return inputs.reduce(function (sum, input) {
            return sum + (parseInt(input.value, 10) || 0);
        }, 0);
    }

    function sync() {
        var sum = total();
        var valid = sum === 100;

        if (totalEl) {
            totalEl.textContent = String(sum);
            totalEl.className = 'fs-4 fw-bold ' + (valid ? 'text-success' : 'text-danger');
        }

        if (barEl) {
            barEl.style.width = Math.min(100, sum) + '%';
            barEl.className = 'progress-bar ' + (valid ? 'bg-success' : 'bg-danger');
        }

        if (messageEl) {
            messageEl.textContent = valid
                ? 'Adds up to 100 — ready to save.'
                : 'Weights must add up to 100. Currently ' + sum + '.';
            messageEl.className = 'form-text mb-0 ' + (valid ? 'text-success' : 'text-danger');
        }

        if (submitButton) {
            submitButton.disabled = !valid;
        }
    }

    inputs.forEach(function (input) {
        var key = input.getAttribute('data-weight-input');
        var range = form.querySelector('[data-weight-range="' + key + '"]');

        input.addEventListener('input', function () {
            if (range) {
                range.value = input.value;
            }
            sync();
        });

        if (range) {
            range.addEventListener('input', function () {
                input.value = range.value;
                sync();
            });
        }
    });

    sync();
});
