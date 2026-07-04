(function () {
    "use strict";

    const root = document.documentElement;
    const toggle = document.getElementById("themeToggle");

    function applyTheme(next) {
        root.setAttribute("data-bs-theme", next);
        localStorage.setItem("sz-theme", next);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute("content", next === "dark" ? "#0f172a" : "#16A34A");
        syncIcon();
    }

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
            applyTheme(isDark ? "light" : "dark");
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
        const form = document.getElementById("applyForm");
        if (!wizard || !form) return;

        let step = 1;
        const panels = document.querySelectorAll(".sz-wizard-panel");
        const steps = wizard.querySelectorAll(".sz-wizard-step");
        const statement = document.getElementById("personalStatement");
        const fileInput = document.getElementById("documentInput");
        const statementPreview = document.getElementById("statementPreview");
        const documentPreview = document.getElementById("documentPreview");
        const statementCount = document.getElementById("statementCount");
        const draftHint = document.getElementById("draftHint");
        const fileNameLabel = document.getElementById("fileNameLabel");
        const dropzone = document.getElementById("fileDropzone");
        const oppId = form.getAttribute("data-opp-id");
        const draftKey = "sz-apply-draft-" + oppId;
        let draftTimer;

        function updateCount() {
            if (!statement || !statementCount) return;
            const len = statement.value.trim().length;
            statementCount.textContent = len + " / 50+";
            statementCount.classList.toggle("text-success", len >= 50);
            statementCount.classList.toggle("text-danger", len > 0 && len < 50);
        }

        function saveDraft() {
            if (!statement || !oppId) return;
            try {
                localStorage.setItem(draftKey, statement.value);
                if (draftHint) {
                    draftHint.classList.add("saved");
                    draftHint.innerHTML = '<i class="bi bi-check-circle me-1"></i>Draft saved';
                }
            } catch (e) { /* ignore quota */ }
        }

        function loadDraft() {
            if (!statement || !oppId) return;
            try {
                const saved = localStorage.getItem(draftKey);
                if (saved && !statement.value.trim()) {
                    statement.value = saved;
                    updateCount();
                }
            } catch (e) { /* ignore */ }
        }

        function clearDraft() {
            try { localStorage.removeItem(draftKey); } catch (e) { /* ignore */ }
        }

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

        loadDraft();
        updateCount();

        if (statement) {
            statement.addEventListener("input", function () {
                updateCount();
                clearTimeout(draftTimer);
                draftTimer = setTimeout(saveDraft, 600);
            });
        }

        if (fileInput && fileNameLabel) {
            fileInput.addEventListener("change", function () {
                fileNameLabel.textContent = fileInput.files.length
                    ? fileInput.files[0].name
                    : "No file selected";
            });
        }

        if (dropzone && fileInput) {
            ["dragenter", "dragover"].forEach(function (ev) {
                dropzone.addEventListener(ev, function (e) {
                    e.preventDefault();
                    dropzone.classList.add("dragover");
                });
            });
            ["dragleave", "drop"].forEach(function (ev) {
                dropzone.addEventListener(ev, function (e) {
                    e.preventDefault();
                    dropzone.classList.remove("dragover");
                });
            });
            dropzone.addEventListener("drop", function (e) {
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    fileInput.dispatchEvent(new Event("change"));
                }
            });
        }

        document.querySelectorAll("[data-wizard-next]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                if (step === 1 && statement && statement.value.trim().length < 50) {
                    statement.classList.add("is-invalid");
                    statement.focus();
                    return;
                }
                if (statement) statement.classList.remove("is-invalid");
                saveDraft();
                showStep(Math.min(step + 1, 3));
            });
        });

        document.querySelectorAll("[data-wizard-back]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                showStep(Math.max(step - 1, 1));
            });
        });

        form.addEventListener("submit", function () {
            clearDraft();
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

    /* Sticky navbar shadow on landing */
    (function initLandingNav() {
        const nav = document.querySelector(".sz-landing-header, .sz-landing-nav-wrap");
        if (!nav) return;

        function onScroll() {
            nav.classList.toggle("scrolled", window.scrollY > 8);
        }

        onScroll();
        window.addEventListener("scroll", onScroll, { passive: true });
    })();

    /* Highlight landing nav anchor for visible section */
    (function initLandingNavSpy() {
        if (!document.body.classList.contains("sz-landing")) return;

        var links = document.querySelectorAll(".sz-landing-nav__anchor");
        if (!links.length || !("IntersectionObserver" in window)) return;

        var sectionIds = [];
        links.forEach(function (link) {
            var href = link.getAttribute("href");
            if (href && href.charAt(0) === "#") sectionIds.push(href.slice(1));
        });

        var sections = sectionIds
            .map(function (id) { return document.getElementById(id); })
            .filter(Boolean);

        if (!sections.length) return;

        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    var id = entry.target.id;
                    links.forEach(function (link) {
                        var active = link.getAttribute("href") === "#" + id;
                        link.classList.toggle("active", active);
                        if (active) link.setAttribute("aria-current", "true");
                        else link.removeAttribute("aria-current");
                    });
                });
            },
            { rootMargin: "-40% 0px -50% 0px", threshold: 0 }
        );

        sections.forEach(function (section) { observer.observe(section); });
    })();

    /* Scroll reveal disabled — production UX keeps all content immediately visible */

    /* Close mobile sidebar after navigation */
    (function initSidebarDismiss() {
        const sidebar = document.getElementById("sidebar");
        if (!sidebar || !window.bootstrap) return;

        sidebar.querySelectorAll(".nav-link").forEach(function (link) {
            link.addEventListener("click", function () {
                if (window.innerWidth >= 768) return;
                const instance = bootstrap.Offcanvas.getInstance(sidebar);
                if (instance) instance.hide();
            });
        });
    })();

    /* Brief loader on in-app navigation */
    (function initPageLoader() {
        if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

        var loader = document.getElementById("sz-page-loader");
        if (!loader) {
            loader = document.createElement("div");
            loader.id = "sz-page-loader";
            loader.className = "sz-page-loader";
            loader.setAttribute("aria-hidden", "true");
            loader.innerHTML =
                '<div class="sz-page-loader__ring" role="status" aria-label="Loading"></div>';
            document.body.appendChild(loader);
        }

        function showLoader() {
            loader.classList.add("is-active");
        }

        document.addEventListener("click", function (e) {
            var link = e.target.closest("a[href]");
            if (!link || link.target === "_blank" || link.hasAttribute("download")) return;
            var href = link.getAttribute("href");
            if (!href || href.charAt(0) === "#" || href.indexOf("javascript:") === 0) return;
            try {
                var url = new URL(link.href, window.location.origin);
                if (url.origin !== window.location.origin) return;
            } catch (err) {
                return;
            }
            showLoader();
        });

        document.addEventListener("submit", function (e) {
            var form = e.target;
            if (!form || !form.tagName || form.tagName.toLowerCase() !== "form") return;
            if (form.method && form.method.toLowerCase() === "get") return;
            if (form.hasAttribute("data-no-loader")) return;
            showLoader();
        });
    })();
})();
