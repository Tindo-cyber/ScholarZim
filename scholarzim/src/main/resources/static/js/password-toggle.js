/**
 * Show/hide toggle for any password field wrapped in an .input-group with a
 * .sz-password-toggle button. Event-delegated so new password fields work
 * automatically with no per-page wiring.
 */
(function () {
    "use strict";

    document.addEventListener("click", function (event) {
        var btn = event.target.closest(".sz-password-toggle");
        if (!btn) return;

        var group = btn.closest(".input-group");
        var input = group && group.querySelector('input[type="password"], input[type="text"].sz-password-field');
        if (!input) return;

        var showing = input.type === "text";
        input.type = showing ? "password" : "text";
        input.classList.toggle("sz-password-field", !showing);

        var icon = btn.querySelector("i");
        if (icon) {
            icon.classList.toggle("bi-eye", showing);
            icon.classList.toggle("bi-eye-slash", !showing);
        }
        btn.setAttribute("aria-label", showing ? "Show password" : "Hide password");
    });
})();
