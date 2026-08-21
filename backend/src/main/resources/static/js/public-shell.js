/**
 * Lightweight public-page shell — theme toggle and flash toasts only.
 */
(function () {
    "use strict";

    document.body.classList.add("sz-page-ready");

    var root = document.documentElement;
    var toggle = document.getElementById("themeToggle");

    function applyTheme(next) {
        root.setAttribute("data-bs-theme", next);
        localStorage.setItem("sz-theme", next);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute("content", next === "dark" ? "#0f172a" : "#16A34A");
        }
        syncIcon();
    }

    function syncIcon() {
        if (!toggle) return;
        var isDark = root.getAttribute("data-bs-theme") === "dark";
        toggle.innerHTML = isDark
            ? '<i class="bi bi-sun" aria-hidden="true"></i>'
            : '<i class="bi bi-moon-stars" aria-hidden="true"></i>';
    }

    syncIcon();

    if (toggle) {
        toggle.addEventListener("click", function () {
            var isDark = root.getAttribute("data-bs-theme") === "dark";
            applyTheme(isDark ? "light" : "dark");
        });
    }

    (function initToasts() {
        var toneMap = {
            "alert-success": "success",
            "alert-danger": "danger",
            "alert-warning": "warning",
            "alert-info": "info"
        };

        function getContainer() {
            var container = document.getElementById("sz-toast-container");
            if (!container) {
                container = document.createElement("div");
                container.id = "sz-toast-container";
                container.className = "toast-container position-fixed top-0 end-0 p-3";
                container.style.zIndex = "1090";
                container.setAttribute("aria-live", "polite");
                container.setAttribute("aria-atomic", "true");
                document.body.appendChild(container);
            }
            return container;
        }

        function showToast(message, tone) {
            if (!message) return;
            tone = tone || "success";
            var container = getContainer();
            var toastEl = document.createElement("div");
            toastEl.className = "toast align-items-center text-bg-" + tone + " border-0";
            toastEl.setAttribute("role", "alert");
            toastEl.innerHTML =
                '<div class="d-flex">' +
                '<div class="toast-body">' + message + '</div>' +
                '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Dismiss notification"></button>' +
                '</div>';
            container.appendChild(toastEl);
            if (window.bootstrap && bootstrap.Toast) {
                var toast = new bootstrap.Toast(toastEl, { delay: 4500 });
                toast.show();
                toastEl.addEventListener("hidden.bs.toast", function () {
                    toastEl.remove();
                });
            }
        }

        window.szShowToast = showToast;

        var alertSelectors = ".alert-success, .alert-danger, .alert-warning, .alert-info";
        document.querySelectorAll(alertSelectors).forEach(function (alert) {
            var tone = "primary";
            Object.keys(toneMap).some(function (cls) {
                if (alert.classList.contains(cls)) {
                    tone = toneMap[cls];
                    return true;
                }
                return false;
            });
            var span = alert.querySelector("span");
            var message = (span && span.textContent ? span.textContent.trim() : alert.textContent.trim());
            if (!message) return;
            showToast(message, tone);
            alert.remove();
        });
    })();

    (function initPageShells() {
        document.documentElement.classList.add("js");
        var shells = document.querySelectorAll(".sz-page-shell");
        if (!shells.length) return;

        var delay = window.matchMedia("(prefers-reduced-motion: reduce)").matches ? 0 : 80;

        window.setTimeout(function () {
            shells.forEach(function (shell) {
                shell.classList.add("is-loaded");
            });
        }, delay);
    })();
})();
