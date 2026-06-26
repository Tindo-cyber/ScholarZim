(function () {
    "use strict";

    const root = document.documentElement;
    const toggle = document.getElementById("themeToggle");

    function syncIcon() {
        if (!toggle) return;
        const isDark = root.getAttribute("data-bs-theme") === "dark";
        toggle.innerHTML = isDark
            ? '<i class="bi bi-sun"></i>'
            : '<i class="bi bi-moon-stars"></i>';
    }

    syncIcon();

    if (toggle) {
        toggle.addEventListener("click", function () {
            const isDark = root.getAttribute("data-bs-theme") === "dark";
            const next = isDark ? "light" : "dark";
            root.setAttribute("data-bs-theme", next);
            localStorage.setItem("sz-theme", next);
            syncIcon();
        });
    }

    /* Flash alerts → Bootstrap toasts */
    (function initToasts() {
        const alerts = document.querySelectorAll(".alert-success, .alert-danger");
        if (!alerts.length) return;

        let container = document.getElementById("sz-toast-container");
        if (!container) {
            container = document.createElement("div");
            container.id = "sz-toast-container";
            container.className = "toast-container position-fixed top-0 end-0 p-3";
            container.style.zIndex = "1090";
            document.body.appendChild(container);
        }

        alerts.forEach(function (alert) {
            const isSuccess = alert.classList.contains("alert-success");
            const message = alert.querySelector("span")?.textContent?.trim() || alert.textContent.trim();
            if (!message) return;

            const toastEl = document.createElement("div");
            toastEl.className = "toast align-items-center text-bg-" + (isSuccess ? "success" : "danger") + " border-0";
            toastEl.setAttribute("role", "alert");
            toastEl.innerHTML =
                '<div class="d-flex">' +
                '<div class="toast-body">' + message + '</div>' +
                '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
                '</div>';
            container.appendChild(toastEl);

            if (window.bootstrap && bootstrap.Toast) {
                const toast = new bootstrap.Toast(toastEl, { delay: 4500 });
                toast.show();
            }

            alert.classList.add("d-none");
        });
    })();

    /* Apply wizard */
    (function initApplyWizard() {
        const wizard = document.getElementById("applyWizard");
        if (!wizard) return;

        let step = 1;
        const panels = document.querySelectorAll(".sz-wizard-panel");
        const steps = wizard.querySelectorAll(".sz-wizard-step");
        const statement = document.getElementById("personalStatement");
        const fileInput = document.getElementById("documentInput");
        const statementPreview = document.getElementById("statementPreview");
        const documentPreview = document.getElementById("documentPreview");

        function showStep(n) {
            step = n;
            panels.forEach(function (p) {
                p.classList.toggle("d-none", parseInt(p.getAttribute("data-panel"), 10) !== n);
            });
            steps.forEach(function (s) {
                const sn = parseInt(s.getAttribute("data-step"), 10);
                s.classList.toggle("active", sn === n);
                s.classList.toggle("completed", sn < n);
            });
            if (n === 3) {
                if (statementPreview && statement) {
                    statementPreview.textContent = statement.value.trim() || "—";
                }
                if (documentPreview && fileInput) {
                    documentPreview.textContent = fileInput.files.length
                        ? fileInput.files[0].name
                        : "No file selected (optional)";
                }
            }
        }

        document.querySelectorAll("[data-wizard-next]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                if (step === 1 && statement && statement.value.trim().length < 50) {
                    statement.classList.add("is-invalid");
                    statement.focus();
                    return;
                }
                if (statement) statement.classList.remove("is-invalid");
                showStep(Math.min(step + 1, 3));
            });
        });

        document.querySelectorAll("[data-wizard-back]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                showStep(Math.max(step - 1, 1));
            });
        });
    })();

    document.querySelectorAll("[data-deadline]").forEach(function (el) {
        const raw = el.getAttribute("data-deadline");
        if (!raw) return;

        const deadline = new Date(raw + "T00:00:00");
        const now = new Date();
        now.setHours(0, 0, 0, 0);
        const daysLeft = Math.ceil((deadline - now) / (1000 * 60 * 60 * 24));

        if (daysLeft < 0) {
            el.classList.add("sz-deadline--urgent");
            el.innerHTML = '<i class="bi bi-exclamation-circle"></i> Closed';
        } else if (daysLeft <= 14) {
            el.classList.add("sz-deadline--urgent");
            el.innerHTML = '<i class="bi bi-alarm"></i> ' + daysLeft + ' day' + (daysLeft === 1 ? '' : 's') + ' left';
        } else if (daysLeft <= 45) {
            el.classList.add("sz-deadline--soon");
            el.innerHTML = '<i class="bi bi-calendar-event"></i> ' + daysLeft + ' days left';
        } else {
            el.classList.add("sz-deadline--ok");
            el.innerHTML = '<i class="bi bi-calendar-check"></i> ' + formatDate(deadline);
        }
    });

    function formatDate(d) {
        return d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
    }

    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (!reducedMotion) {
        document.querySelectorAll(".sz-match-bar__fill").forEach(function (bar) {
            const target = bar.style.width;
            bar.style.width = "0";
            requestAnimationFrame(function () {
                bar.style.width = target;
            });
        });
    }
})();
