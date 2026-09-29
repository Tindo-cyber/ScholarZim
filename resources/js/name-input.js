/**
 * Strips characters a person's name cannot contain - digits, most
 * punctuation - as the field is typed into or pasted into, so the HTML
 * `pattern` attribute is never the first time an applicant hears about it.
 * The server-side FormOptions::NAME_PATTERN rule is still what actually
 * protects the data; this only saves a round trip to find out.
 *
 * The 'input' event fires for both typing and pasting (once the paste has
 * landed), so one listener covers both without a separate 'paste' handler.
 *
 * One field - the admin "create user" form's full_name - is a person's name
 * only for some of the roles it can create: a provider account uses the
 * same field as an organisation name. data-name-input-role-check names the
 * sibling role field to read, and data-name-input-skip-role the value that
 * turns sanitising off for as long as that role is selected.
 */
document.addEventListener('DOMContentLoaded', function () {
    var DISALLOWED = /[^\p{L}\s'-]/gu;

    function sanitise(input) {
        if (isSkipped(input)) {
            return;
        }

        var next = input.value.replace(DISALLOWED, '');

        if (next !== input.value) {
            var position = input.selectionStart;
            input.value = next;
            if (position !== null) {
                input.setSelectionRange(position, position);
            }
        }
    }

    function isSkipped(input) {
        var roleFieldName = input.getAttribute('data-name-input-role-check');

        if (!roleFieldName) {
            return false;
        }

        var form = input.closest('form');
        var roleField = form ? form.elements.namedItem(roleFieldName) : null;

        return !!roleField && roleField.value === input.getAttribute('data-name-input-skip-role');
    }

    document.querySelectorAll('[data-name-input]').forEach(function (input) {
        input.addEventListener('input', function () {
            sanitise(input);
        });

        var roleFieldName = input.getAttribute('data-name-input-role-check');
        var form = input.closest('form');
        var roleField = roleFieldName && form ? form.elements.namedItem(roleFieldName) : null;

        // Re-run once the role changes, so switching away from "Provider"
        // strips any digit typed while it was selected.
        if (roleField) {
            roleField.addEventListener('change', function () {
                sanitise(input);
            });
        }
    });
});
