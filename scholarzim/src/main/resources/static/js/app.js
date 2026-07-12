(function () {
    "use strict";

    if (!document.body.classList.contains("sz-landing")) {
        document.body.classList.add("sz-page-ready");
    }

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
        var alertSelectors = ".alert-success, .alert-danger, .alert-warning, .alert-info";
        var alerts = document.querySelectorAll(alertSelectors);
        if (!alerts.length) return;

        var toneMap = {
            "alert-success": "success",
            "alert-danger": "danger",
            "alert-warning": "warning",
            "alert-info": "info"
        };

        var container = document.getElementById("sz-toast-container");
        if (!container) {
            container = document.createElement("div");
            container.id = "sz-toast-container";
            container.className = "toast-container position-fixed top-0 end-0 p-3";
            container.style.zIndex = "1090";
            document.body.appendChild(container);
        }

        alerts.forEach(function (alert) {
            var tone = "primary";
            Object.keys(toneMap).some(function (cls) {
                if (alert.classList.contains(cls)) {
                    tone = toneMap[cls];
                    return true;
                }
                return false;
            });

            var message = alert.querySelector("span")?.textContent?.trim() || alert.textContent.trim();
            if (!message) return;

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

    /* Drag-and-drop uploads: validation, preview, submit guard */
    (function initFileUploads() {
        var MAX_BYTES = 5 * 1024 * 1024;
        var TYPE_MAP = {
            pdf: {
                mimes: ["application/pdf"],
                exts: [".pdf"],
                label: "PDF"
            },
            image: {
                mimes: ["image/jpeg", "image/png", "image/webp"],
                exts: [".jpg", ".jpeg", ".png", ".webp"],
                label: "image (JPG, PNG, WebP)"
            }
        };

        function parseKinds(typesStr) {
            return (typesStr || "pdf,image").split(",").map(function (s) {
                return s.trim();
            }).filter(Boolean);
        }

        function validateFile(file, kinds) {
            if (!file) return null;
            if (file.size > MAX_BYTES) {
                return "File must be smaller than 5 MB.";
            }
            var name = (file.name || "").toLowerCase();
            var ok = kinds.some(function (kind) {
                var spec = TYPE_MAP[kind];
                if (!spec) return false;
                if (file.type && spec.mimes.indexOf(file.type) !== -1) return true;
                return spec.exts.some(function (ext) {
                    return name.endsWith(ext);
                });
            });
            if (!ok) {
                return "Please choose a " + kinds.map(function (k) {
                    return TYPE_MAP[k] ? TYPE_MAP[k].label : k;
                }).join(" or ") + " file.";
            }
            return null;
        }

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + " B";
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + " KB";
            return (bytes / (1024 * 1024)).toFixed(1) + " MB";
        }

        function assignFiles(input, fileList) {
            try {
                var dt = new DataTransfer();
                for (var i = 0; i < fileList.length; i++) {
                    dt.items.add(fileList[i]);
                }
                input.files = dt.files;
            } catch (err) {
                input.files = fileList;
            }
        }

        document.querySelectorAll("[data-file-upload]").forEach(function (zone) {
            var inputId = zone.getAttribute("data-file-upload");
            var input = inputId
                ? document.getElementById(inputId)
                : zone.querySelector(".sz-file-upload__input");
            if (!input) return;

            var nameEl = zone.querySelector(".sz-file-upload__name");
            var errorEl = zone.querySelector(".sz-file-upload__error");
            var previewEl = zone.querySelector(".sz-file-upload__preview");
            var emptyLabel = zone.getAttribute("data-empty-label") || "No file selected";
            var kinds = parseKinds(zone.getAttribute("data-accept-types"));
            var objectUrl = null;

            function clearPreview() {
                if (objectUrl) {
                    URL.revokeObjectURL(objectUrl);
                    objectUrl = null;
                }
                if (previewEl) {
                    previewEl.classList.add("d-none");
                    previewEl.innerHTML = "";
                }
            }

            function setError(msg) {
                zone.classList.add("sz-file-upload--error");
                zone.classList.remove("sz-file-upload--valid");
                if (errorEl) {
                    errorEl.textContent = msg;
                    errorEl.classList.remove("d-none");
                }
                input.setCustomValidity(msg || "Invalid file");
            }

            function clearError() {
                zone.classList.remove("sz-file-upload--error");
                if (errorEl) {
                    errorEl.classList.add("d-none");
                    errorEl.textContent = "";
                }
                input.setCustomValidity("");
            }

            function handleFile(file) {
                clearError();
                if (!file) {
                    clearPreview();
                    zone.classList.remove("sz-file-upload--valid");
                    if (nameEl) nameEl.textContent = emptyLabel;
                    return true;
                }
                var err = validateFile(file, kinds);
                if (err) {
                    setError(err);
                    clearPreview();
                    if (nameEl) nameEl.textContent = emptyLabel;
                    return false;
                }
                zone.classList.add("sz-file-upload--valid");
                if (nameEl) nameEl.textContent = file.name + " · " + formatSize(file.size);

                if (previewEl) {
                    clearPreview();
                    previewEl.classList.remove("d-none");
                    var lower = (file.name || "").toLowerCase();
                    if (file.type === "application/pdf" || lower.endsWith(".pdf")) {
                        objectUrl = URL.createObjectURL(file);
                        previewEl.innerHTML =
                            '<div class="sz-file-preview"><iframe class="sz-file-preview__pdf" title="PDF preview" src="' +
                            objectUrl + '#toolbar=0"></iframe></div>';
                    } else if (file.type && file.type.indexOf("image/") === 0) {
                        objectUrl = URL.createObjectURL(file);
                        previewEl.innerHTML =
                            '<div class="sz-file-preview"><img class="sz-file-preview__img" alt="Preview" src="' +
                            objectUrl + '"></div>';
                    }
                }
                return true;
            }

            input.addEventListener("change", function () {
                handleFile(input.files.length ? input.files[0] : null);
            });

            ["dragenter", "dragover"].forEach(function (ev) {
                zone.addEventListener(ev, function (e) {
                    e.preventDefault();
                    zone.classList.add("dragover");
                });
            });
            ["dragleave", "drop"].forEach(function (ev) {
                zone.addEventListener(ev, function (e) {
                    e.preventDefault();
                    zone.classList.remove("dragover");
                });
            });
            zone.addEventListener("drop", function (e) {
                if (e.dataTransfer.files.length) {
                    assignFiles(input, e.dataTransfer.files);
                    input.dispatchEvent(new Event("change"));
                }
            });
        });

        document.querySelectorAll("form[enctype='multipart/form-data']").forEach(function (form) {
            form.addEventListener("submit", function (e) {
                var valid = true;
                form.querySelectorAll("[data-file-upload]").forEach(function (zone) {
                    var inputId = zone.getAttribute("data-file-upload");
                    var inp = inputId
                        ? document.getElementById(inputId)
                        : zone.querySelector(".sz-file-upload__input");
                    if (!inp) return;

                    if (inp.required && (!inp.files || !inp.files.length)) {
                        valid = false;
                        zone.classList.add("sz-file-upload--error");
                        var reqErr = zone.querySelector(".sz-file-upload__error");
                        if (reqErr) {
                            reqErr.textContent = "Please select a file.";
                            reqErr.classList.remove("d-none");
                        }
                        zone.scrollIntoView({ behavior: "smooth", block: "center" });
                        return;
                    }

                    if (inp.files && inp.files.length) {
                        var err = validateFile(inp.files[0], parseKinds(zone.getAttribute("data-accept-types")));
                        if (err) {
                            valid = false;
                            zone.classList.add("sz-file-upload--error");
                            var errBox = zone.querySelector(".sz-file-upload__error");
                            if (errBox) {
                                errBox.textContent = err;
                                errBox.classList.remove("d-none");
                            }
                            inp.setCustomValidity(err);
                        }
                    }
                });
                if (!valid) e.preventDefault();
            });
        });
    })();

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

    /* Accessibility — skip link target, live region, theme announcement */
    (function initAccessibility() {
        var main = document.getElementById("main-content")
            || document.querySelector("main")
            || document.querySelector(".sz-content")
            || document.querySelector(".sz-auth-wrap");
        if (main && main.id !== "main-content") {
            main.id = "main-content";
        }
        if (main && !main.hasAttribute("tabindex")) {
            main.setAttribute("tabindex", "-1");
        }

        var skip = document.querySelector(".sz-skip-link");
        if (skip && main) {
            skip.addEventListener("click", function (e) {
                e.preventDefault();
                main.focus({ preventScroll: false });
            });
        }

        if (!document.getElementById("sz-live-region")) {
            var live = document.createElement("div");
            live.id = "sz-live-region";
            live.setAttribute("aria-live", "polite");
            live.setAttribute("aria-atomic", "true");
            document.body.appendChild(live);
        }

        var themeBtn = document.getElementById("themeToggle");
        if (themeBtn) {
            themeBtn.addEventListener("click", function () {
                var mode = root.getAttribute("data-bs-theme") === "dark" ? "Dark" : "Light";
                var liveRegion = document.getElementById("sz-live-region");
                if (liveRegion) {
                    liveRegion.textContent = mode + " mode enabled";
                }
            });
        }
    })();

    /* Close mobile sidebar after navigation */
    (function initSidebarDismiss() {
        const sidebar = document.getElementById("sidebar");
        if (!sidebar || !window.bootstrap) return;

        sidebar.addEventListener("shown.bs.offcanvas", function () {
            document.body.classList.add("sz-drawer-open");
        });
        sidebar.addEventListener("hidden.bs.offcanvas", function () {
            document.body.classList.remove("sz-drawer-open");
        });

        sidebar.querySelectorAll(".nav-link").forEach(function (link) {
            link.addEventListener("click", function () {
                if (window.innerWidth >= 768) return;
                const instance = bootstrap.Offcanvas.getInstance(sidebar);
                if (instance) instance.hide();
            });
        });
    })();

    /* Collapsible sidebar, search focus, keyboard shortcuts */
    (function initNavigation() {
        var shell = document.getElementById("szShell");
        var collapseBtns = document.querySelectorAll("[data-sidebar-collapse]");
        var searchInput = document.getElementById("szGlobalSearch");
        var shortcutsModal = document.getElementById("szShortcutsModal");
        var shortcutsInstance = shortcutsModal && window.bootstrap
            ? bootstrap.Modal.getOrCreateInstance(shortcutsModal)
            : null;

        function setCollapsed(collapsed) {
            if (!shell) return;
            shell.classList.toggle("sz-shell--sidebar-collapsed", collapsed);
            localStorage.setItem("sz-sidebar-collapsed", collapsed ? "1" : "0");
            collapseBtns.forEach(function (btn) {
                btn.setAttribute("aria-pressed", collapsed ? "true" : "false");
                btn.setAttribute("aria-label", collapsed ? "Expand sidebar" : "Collapse sidebar");
                var icon = btn.querySelector("i");
                if (icon) {
                    icon.className = collapsed
                        ? "bi bi-layout-sidebar"
                        : "bi bi-layout-sidebar-inset";
                }
            });
        }

        if (shell && window.innerWidth >= 768 && localStorage.getItem("sz-sidebar-collapsed") === "1") {
            setCollapsed(true);
        }

        collapseBtns.forEach(function (btn) {
            btn.addEventListener("click", function () {
                if (!shell) return;
                setCollapsed(!shell.classList.contains("sz-shell--sidebar-collapsed"));
            });
        });

        function focusSearch() {
            if (!searchInput || window.innerWidth < 992) return false;
            searchInput.focus();
            searchInput.select();
            return true;
        }

        function isTypingContext(target) {
            if (!target) return false;
            var tag = target.tagName ? target.tagName.toLowerCase() : "";
            return tag === "input" || tag === "textarea" || tag === "select" || target.isContentEditable;
        }

        document.addEventListener("keydown", function (e) {
            if (isTypingContext(e.target)) {
                if (e.key === "Escape") {
                    e.target.blur();
                }
                return;
            }

            if (e.key === "/" && !e.metaKey && !e.ctrlKey && !e.altKey) {
                if (focusSearch()) e.preventDefault();
                return;
            }

            if (e.key === "?" && !e.metaKey && !e.ctrlKey && !e.altKey) {
                if (shortcutsInstance) {
                    shortcutsInstance.show();
                    e.preventDefault();
                }
                return;
            }

            if ((e.key === "b" || e.key === "B") && !e.metaKey && !e.ctrlKey && !e.altKey) {
                if (shell && window.innerWidth >= 768) {
                    setCollapsed(!shell.classList.contains("sz-shell--sidebar-collapsed"));
                    e.preventDefault();
                }
                return;
            }

            if ((e.key === "t" || e.key === "T") && !e.metaKey && !e.ctrlKey && !e.altKey) {
                var themeBtn = document.getElementById("themeToggle");
                if (themeBtn) {
                    themeBtn.click();
                    e.preventDefault();
                }
            }
        });
    })();

    /* Smooth page enter */
    (function initPageReveal() {
        if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
            document.body.classList.add("sz-page-ready");
            return;
        }
        requestAnimationFrame(function () {
            document.body.classList.add("sz-page-ready");
        });
    })();

    /* Accessible confirmation dialogs (replaces native confirm) */
    (function initConfirmDialogs() {
        var modalEl = document.getElementById("szConfirmModal");
        if (!modalEl || !window.bootstrap) return;

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        var titleEl = document.getElementById("szConfirmTitle");
        var bodyEl = document.getElementById("szConfirmBody");
        var iconEl = document.getElementById("szConfirmIcon");
        var confirmBtn = document.getElementById("szConfirmBtn");
        var pendingForm = null;
        var pendingSubmitter = null;

        function openConfirm(form, message, title, variant, submitter) {
            pendingForm = form;
            pendingSubmitter = submitter || null;

            if (titleEl) titleEl.textContent = title || "Confirm action";
            if (bodyEl) bodyEl.textContent = message;

            if (iconEl) {
                iconEl.className = "sz-confirm-modal__icon"
                    + (variant === "warning" ? " sz-confirm-modal__icon--warning" : "");
                iconEl.innerHTML = variant === "warning"
                    ? '<i class="bi bi-exclamation-circle-fill"></i>'
                    : '<i class="bi bi-exclamation-triangle-fill"></i>';
            }

            if (confirmBtn) {
                confirmBtn.className = "btn btn-sm "
                    + (variant === "warning" ? "btn-warning" : "btn-danger");
                confirmBtn.textContent = variant === "warning" ? "Continue" : "Confirm";
            }

            modal.show();
        }

        if (confirmBtn) {
            confirmBtn.addEventListener("click", function () {
                if (!pendingForm) return;
                var form = pendingForm;
                var submitter = pendingSubmitter;
                pendingForm = null;
                pendingSubmitter = null;
                modal.hide();
                form.dataset.szConfirmed = "1";
                if (typeof form.requestSubmit === "function") {
                    form.requestSubmit(submitter || undefined);
                } else {
                    form.submit();
                }
            });
        }

        document.addEventListener("submit", function (e) {
            var form = e.target;
            if (!form || !form.tagName || form.tagName.toLowerCase() !== "form") return;
            var message = form.getAttribute("data-confirm");
            if (!message) return;
            if (form.dataset.szConfirmed === "1") {
                delete form.dataset.szConfirmed;
                return;
            }
            e.preventDefault();
            openConfirm(
                form,
                message,
                form.getAttribute("data-confirm-title"),
                form.getAttribute("data-confirm-variant") || "danger",
                e.submitter || null
            );
        }, true);
    })();

    /* Prevent double-submit and show button loading state */
    (function initSubmitButtons() {
        document.addEventListener("submit", function (e) {
            var form = e.target;
            if (!form || !form.tagName || form.tagName.toLowerCase() !== "form") return;
            if (form.method && form.method.toLowerCase() === "get") return;
            if (form.hasAttribute("data-no-loader")) return;
            if (e.defaultPrevented) return;

            form.querySelectorAll('[type="submit"]').forEach(function (btn) {
                if (btn.disabled || btn.classList.contains("is-loading")) return;
                btn.disabled = true;
                btn.classList.add("is-loading");
                var label = btn.textContent.trim();
                btn.innerHTML = '<span class="sz-btn-skeleton" aria-hidden="true"></span>'
                    + '<span class="sz-btn-label">' + label + '</span>';
            });
        });
    })();

    /* Mark chart panels ready after Chart.js renders */
    window.szMarkChartsReady = function () {
        document.querySelectorAll(".sz-chart-wrap").forEach(function (panel) {
            panel.classList.add("is-ready");
        });
    };

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
                '<div class="sz-page-loader__panel">' +
                '<div class="sz-page-loader__skeleton sz-skeleton-nav-overlay" role="status" aria-live="polite">' +
                '<div class="sz-skeleton sz-skeleton-line sz-skeleton-line--title"></div>' +
                '<div class="sz-skeleton sz-skeleton-line"></div>' +
                '<div class="sz-skeleton sz-skeleton-line sz-skeleton-line--short"></div>' +
                '</div>' +
                '<p class="sz-page-loader__text small text-secondary mb-0 mt-2 d-none" id="sz-page-loader-text"></p>' +
                '<div class="sz-upload-progress d-none" id="sz-page-loader-progress">' +
                '<div class="sz-upload-progress__bar"></div></div></div>';
            document.body.appendChild(loader);
        }

        var loaderText = document.getElementById("sz-page-loader-text");
        var loaderProgress = document.getElementById("sz-page-loader-progress");

        function showLoader(message) {
            if (loaderText) {
                if (message) {
                    loaderText.textContent = message;
                    loaderText.classList.remove("d-none");
                } else {
                    loaderText.textContent = "";
                    loaderText.classList.add("d-none");
                }
            }
            if (loaderProgress) {
                loaderProgress.classList.toggle("d-none", !message);
            }
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
            if (e.defaultPrevented) return;

            var uploadMsg = null;
            if (form.getAttribute("enctype") === "multipart/form-data") {
                form.querySelectorAll('input[type="file"]').forEach(function (inp) {
                    if (inp.files && inp.files.length) uploadMsg = "Uploading file…";
                });
            }
            showLoader(uploadMsg);
        });
    })();

    /* Page skeleton reveal — dashboard, profile, notifications, applications, messages, scholarships */
    (function initPageShells() {
        var shells = document.querySelectorAll(".sz-page-shell");
        if (!shells.length) return;

        var delay = window.matchMedia("(prefers-reduced-motion: reduce)").matches ? 0 : 260;

        window.setTimeout(function () {
            shells.forEach(function (shell) {
                shell.classList.add("is-loaded");
            });
        }, delay);
    })();

    /* Applicant dashboard — deadline calendar */
    (function initApplicantDashboard() {
        var root = document.getElementById("szApplicantDashboard");
        if (!root) return;

        var calendarEl = document.getElementById("szDashCalendar");
        var eventsEl = document.getElementById("szDashCalendarEvents");
        if (!calendarEl || !eventsEl) return;

        var events = [];
        eventsEl.querySelectorAll(".sz-dash-calendar__event").forEach(function (node) {
            var date = node.getAttribute("data-date");
            var title = node.getAttribute("data-title");
            if (date) events.push({ date: date, title: title || "" });
        });

        var viewDate = new Date();
        var monthNames = [
            "January", "February", "March", "April", "May", "June",
            "July", "August", "September", "October", "November", "December"
        ];
        var weekdayNames = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

        function dateKey(y, m, d) {
            return y + "-" + String(m + 1).padStart(2, "0") + "-" + String(d).padStart(2, "0");
        }

        function hasDeadline(y, m, d) {
            return events.some(function (e) { return e.date === dateKey(y, m, d); });
        }

        function renderCalendar() {
            var year = viewDate.getFullYear();
            var month = viewDate.getMonth();
            var today = new Date();
            var firstDay = new Date(year, month, 1).getDay();
            var daysInMonth = new Date(year, month + 1, 0).getDate();
            var daysInPrev = new Date(year, month, 0).getDate();

            var html = '<div class="sz-dash-calendar__header">';
            html += '<span class="sz-dash-calendar__month">' + monthNames[month] + " " + year + "</span>";
            html += '<div class="sz-dash-calendar__nav">';
            html += '<button type="button" class="sz-dash-calendar__nav-btn" data-cal-nav="-1" aria-label="Previous month"><i class="bi bi-chevron-left"></i></button>';
            html += '<button type="button" class="sz-dash-calendar__nav-btn" data-cal-nav="1" aria-label="Next month"><i class="bi bi-chevron-right"></i></button>';
            html += "</div></div>";

            html += '<div class="sz-dash-calendar__weekdays">';
            weekdayNames.forEach(function (w) {
                html += '<span class="sz-dash-calendar__weekday">' + w + "</span>";
            });
            html += "</div><div class=\"sz-dash-calendar__days\">";

            for (var i = firstDay - 1; i >= 0; i--) {
                html += '<span class="sz-dash-calendar__day sz-dash-calendar__day--muted">' + (daysInPrev - i) + "</span>";
            }

            for (var d = 1; d <= daysInMonth; d++) {
                var cls = "sz-dash-calendar__day";
                if (year === today.getFullYear() && month === today.getMonth() && d === today.getDate()) {
                    cls += " sz-dash-calendar__day--today";
                }
                if (hasDeadline(year, month, d)) {
                    cls += " sz-dash-calendar__day--deadline";
                }
                html += '<span class="' + cls + '" title="' + (hasDeadline(year, month, d) ? "Deadline" : "") + '">' + d + "</span>";
            }

            var totalCells = firstDay + daysInMonth;
            var trailing = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
            for (var t = 1; t <= trailing; t++) {
                html += '<span class="sz-dash-calendar__day sz-dash-calendar__day--muted">' + t + "</span>";
            }

            html += "</div>";
            calendarEl.innerHTML = html;

            calendarEl.querySelectorAll("[data-cal-nav]").forEach(function (btn) {
                btn.addEventListener("click", function () {
                    viewDate.setMonth(viewDate.getMonth() + parseInt(btn.getAttribute("data-cal-nav"), 10));
                    renderCalendar();
                });
            });
        }

        renderCalendar();
    })();

    /* Scholarship cards — share + favorite animation */
    (function initScholarshipCards() {
        document.querySelectorAll(".sz-scholarship-card__share").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var url = btn.getAttribute("data-share-url");
                var title = btn.getAttribute("data-share-title") || "Scholarship on ScholarZim";
                if (!url) return;
                var fullUrl = url.startsWith("http") ? url : (window.location.origin + url);

                if (navigator.share) {
                    navigator.share({ title: title, url: fullUrl }).catch(function () {});
                    return;
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(fullUrl).then(function () {
                        btn.classList.add("is-shared");
                        var icon = btn.querySelector(".bi");
                        if (icon) {
                            icon.classList.remove("bi-share");
                            icon.classList.add("bi-check2");
                        }
                        window.setTimeout(function () {
                            btn.classList.remove("is-shared");
                            if (icon) {
                                icon.classList.add("bi-share");
                                icon.classList.remove("bi-check2");
                            }
                        }, 2000);
                    });
                }
            });
        });

        document.querySelectorAll("[data-scholarship-save]").forEach(function (form) {
            form.addEventListener("submit", function () {
                var bookmark = form.querySelector(".sz-scholarship-card__bookmark");
                var saveBtn = form.querySelector(".sz-scholarship-card__btn");
                if (bookmark) {
                    bookmark.classList.add("is-animating", "is-favorited");
                    window.setTimeout(function () {
                        bookmark.classList.remove("is-animating");
                    }, 450);
                }
                if (saveBtn) {
                    saveBtn.classList.add("is-favorited");
                    var icon = saveBtn.querySelector(".bi");
                    if (icon) {
                        icon.classList.remove("bi-bookmark");
                        icon.classList.add("bi-bookmark-fill");
                    }
                    var label = saveBtn.querySelector("span");
                    if (label) label.textContent = "Saved";
                }
            });
        });
    })();

    /* Error state actions */
    (function initErrorStates() {
        document.querySelectorAll(".sz-error-state__retry").forEach(function (btn) {
            if (btn.dataset.bound === "1") return;
            btn.dataset.bound = "1";
            btn.addEventListener("click", function () {
                var url = btn.getAttribute("data-retry-url");
                if (url) {
                    window.location.assign(url);
                } else {
                    window.location.reload();
                }
            });
        });
    })();

    /* Network connectivity overlay */
    (function initNetworkError() {
        var overlay = document.getElementById("sz-network-error");
        if (!overlay) return;

        function sync() {
            var offline = !navigator.onLine;
            overlay.classList.toggle("d-none", !offline);
            overlay.setAttribute("aria-hidden", offline ? "false" : "true");
            document.body.classList.toggle("sz-offline", offline);
        }

        window.addEventListener("online", sync);
        window.addEventListener("offline", sync);
        sync();
    })();
})();
