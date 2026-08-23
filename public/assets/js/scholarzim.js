/* Small, dependency-free behaviours shared across hand-rolled form components.
 * Delegated listeners so this keeps working with content swapped in later. */
(function () {
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-password-toggle]');
        if (!toggle) return;

        var input = document.getElementById(toggle.getAttribute('data-password-toggle'));
        if (!input) return;

        var willShow = input.type === 'password';
        input.type = willShow ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', String(willShow));
        toggle.setAttribute('aria-label', willShow ? 'Hide password' : 'Show password');

        var show = toggle.querySelector('[data-icon-show]');
        var hide = toggle.querySelector('[data-icon-hide]');
        if (show) show.classList.toggle('d-none', willShow);
        if (hide) hide.classList.toggle('d-none', !willShow);
    });

    document.addEventListener('input', function (event) {
        if (event.target.matches('[data-password-rules]')) {
            var checklist = document.querySelector('[data-password-checklist="' + event.target.id + '"]');
            if (!checklist) return;

            var value = event.target.value;
            var met = {
                length: value.length >= 8,
                letter: /[A-Za-z]/.test(value),
                number: /[0-9]/.test(value),
            };

            checklist.querySelectorAll('[data-rule]').forEach(function (item) {
                var ok = met[item.getAttribute('data-rule')];
                item.classList.toggle('text-success', ok);
                item.classList.toggle('text-secondary', !ok);

                var pending = item.querySelector('[data-icon-pending]');
                var done = item.querySelector('[data-icon-met]');
                if (pending) pending.classList.toggle('d-none', ok);
                if (done) done.classList.toggle('d-none', !ok);
            });
        }

        if (event.target.matches('[data-file-preview]')) {
            var preview = document.querySelector('[data-file-preview-for="' + event.target.id + '"]');
            var drop = event.target.closest('.sz-file-drop');

            var file = event.target.files && event.target.files[0];
            if (preview) {
                preview.textContent = file
                    ? file.name + ' · ' + (file.size / (1024 * 1024)).toFixed(1) + ' MB'
                    : '';
                preview.classList.toggle('d-none', !file);
            }
            if (drop && file) drop.classList.remove('border-danger');
        }

        // Clear a field's invalid styling as soon as its value satisfies the
        // browser's own constraint validation, rather than waiting for resubmit.
        if (event.target.classList.contains('is-invalid') && typeof event.target.checkValidity === 'function'
            && event.target.checkValidity()) {
            event.target.classList.remove('is-invalid');
        }
    });

    // Multi-step forms: panels are marked [data-step="N"]; a form with no
    // panels is left alone, so this degrades to a normal single-page form.
    document.querySelectorAll('[data-stepped-form]').forEach(function (form) {
        var panels = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
        if (!panels.length) return;

        function showStep(step) {
            panels.forEach(function (panel) {
                panel.classList.toggle('d-none', Number(panel.getAttribute('data-step')) !== step);
            });

            form.querySelectorAll('[data-step-dot]').forEach(function (dot) {
                var n = Number(dot.getAttribute('data-step-dot'));
                dot.classList.remove('is-active', 'is-done');
                if (n < step) dot.classList.add('is-done');
                else if (n === step) dot.classList.add('is-active');
            });

            form.querySelectorAll('[data-step-label]').forEach(function (label) {
                var n = Number(label.getAttribute('data-step-label'));
                label.classList.toggle('text-primary', n <= step);
                label.classList.toggle('fw-semibold', n <= step);
                label.classList.toggle('text-secondary', n > step);
            });

            var line = form.querySelector('[data-step-line]');
            if (line) line.classList.toggle('bg-primary', step > 1);

            form.dataset.currentStep = String(step);
        }

        form.querySelectorAll('[data-step-next]').forEach(function (button) {
            button.addEventListener('click', function () {
                var current = Number(form.dataset.currentStep || 1);
                var fields = form.querySelectorAll('[data-step="' + current + '"] input, [data-step="' + current + '"] select, [data-step="' + current + '"] textarea');
                var firstInvalid = null;

                fields.forEach(function (field) {
                    var drop = field.closest('.sz-file-drop');
                    var ok = field.checkValidity();

                    field.classList.toggle('is-invalid', !ok);
                    if (drop) drop.classList.toggle('border-danger', !ok);
                    if (!ok) firstInvalid = firstInvalid || field;
                });

                if (firstInvalid) {
                    firstInvalid.focus();
                    return;
                }

                showStep(current + 1);
            });
        });

        form.querySelectorAll('[data-step-back]').forEach(function (button) {
            button.addEventListener('click', function () {
                showStep(Number(form.dataset.currentStep || 1) - 1);
            });
        });

        showStep(Number(form.dataset.initialStep) || 1);
    });
})();
