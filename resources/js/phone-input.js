/**
 * Keeps letters out of phone fields as they are typed or pasted, so nobody finds out from an error after submitting.
 *
 *   data-phone-input="digits"         applicants and guardians: digits only (the server wants exactly 10).
 *   data-phone-input="international"  providers: digits, a leading +, and the spaces, brackets, dots and hyphens numbers are
 *                                     written with; an extension ("x123", "ext 12", "#12") keeps its marker but only digits after it.
 *   data-phone-input="by-role"        the admin form: follows the sibling role field named in data-phone-input-role-check,
 *                                     "international" while data-phone-input-international-role is selected, "digits" otherwise.
 *
 * The server (FormOptions::PHONE_PATTERN and App\Rules\InternationalPhoneNumber) is still what protects the data.
 */
document.addEventListener('DOMContentLoaded', function () {
    var EXTENSION = /(ext\.?|x|#)/i;

    function digitsOnly(value) {
        return value.replace(/\D/g, '');
    }

    function international(value) {
        var match = EXTENSION.exec(value);
        var main = match ? value.slice(0, match.index) : value;
        var tail = match ? value.slice(match.index + match[0].length) : '';
        var extension = match ? match[0].toLowerCase() + (/^\s/.test(tail) ? ' ' : '') + digitsOnly(tail) : '';
        var lead = main.trimStart().charAt(0) === '+' ? '+' : '';

        main = lead + main.replace(/[^0-9 ().\-]/g, '');

        return main + extension;
    }

    function modeOf(input) {
        var mode = input.getAttribute('data-phone-input');

        if (mode !== 'by-role') {
            return mode;
        }

        var form = input.closest('form');
        var role = form ? form.elements.namedItem(input.getAttribute('data-phone-input-role-check')) : null;

        return role && role.value === input.getAttribute('data-phone-input-international-role') ? 'international' : 'digits';
    }

    function clean(input) {
        var next = modeOf(input) === 'international' ? international(input.value) : digitsOnly(input.value);

        if (next !== input.value) {
            var position = input.selectionStart;
            input.value = next;
            if (position !== null) {
                input.setSelectionRange(Math.min(position, next.length), Math.min(position, next.length));
            }
        }
    }

    document.querySelectorAll('[data-phone-input]').forEach(function (input) {
        input.addEventListener('input', function () { clean(input); });

        if (input.getAttribute('data-phone-input') === 'by-role') {
            var form = input.closest('form');
            var role = form ? form.elements.namedItem(input.getAttribute('data-phone-input-role-check')) : null;
            if (role) { role.addEventListener('change', function () { clean(input); }); }
        }
    });
});
