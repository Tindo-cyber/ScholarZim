/**
 * ScholarZim landing page — scroll reveal and motion (respects reduced motion).
 */
(function () {
    "use strict";

    if (!document.body.classList.contains("sz-landing")) return;

    var prefersReduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    function initReveal() {
        var items = document.querySelectorAll(".sz-reveal");
        if (!items.length) return;

        if (prefersReduced || !("IntersectionObserver" in window)) {
            items.forEach(function (el) { el.classList.add("is-visible"); });
            return;
        }

        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add("is-visible");
                        observer.unobserve(entry.target);
                    }
                });
            },
            { rootMargin: "0px 0px -8% 0px", threshold: 0.08 }
        );

        items.forEach(function (el) { observer.observe(el); });
    }

    function initSmoothAnchors() {
        document.querySelectorAll('a[href^="#"]').forEach(function (link) {
            link.addEventListener("click", function (e) {
                var id = link.getAttribute("href");
                if (!id || id.length < 2) return;
                var target = document.querySelector(id);
                if (!target) return;
                e.preventDefault();
                var header = document.getElementById("szLandingHeader");
                var offset = header ? header.offsetHeight + 8 : 80;
                var top = target.getBoundingClientRect().top + window.scrollY - offset;
                window.scrollTo({ top: top, behavior: prefersReduced ? "auto" : "smooth" });
                if (history.pushState) history.pushState(null, "", id);
            });
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () {
            initReveal();
            initSmoothAnchors();
        });
    } else {
        initReveal();
        initSmoothAnchors();
    }
})();
