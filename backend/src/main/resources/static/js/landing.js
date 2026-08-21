/**
 * ScholarZim landing page — scroll reveal, counters, and motion.
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

        items.forEach(function (el) {
            var rect = el.getBoundingClientRect();
            if (rect.top < window.innerHeight * 0.92) {
                el.classList.add("is-visible");
            }
        });
    }

    function initSmoothAnchors() {
        document.querySelectorAll('a[href*="#"]').forEach(function (link) {
            link.addEventListener("click", function (e) {
                var href = link.getAttribute("href");
                if (!href || href.indexOf("#") === -1) return;
                var hash = href.substring(href.indexOf("#"));
                if (!hash || hash.length < 2) return;
                var target = document.querySelector(hash);
                if (!target) return;
                e.preventDefault();
                var header = document.getElementById("szLandingHeader");
                var offset = header ? header.offsetHeight + 8 : 80;
                var top = target.getBoundingClientRect().top + window.scrollY - offset;
                window.scrollTo({ top: top, behavior: prefersReduced ? "auto" : "smooth" });
            });
        });
    }

    function animateCounter(el, target, duration) {
        var start = 0;
        var startTime = null;

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            var current = Math.round(start + (target - start) * eased);
            el.textContent = String(current);
            if (progress < 1) {
                window.requestAnimationFrame(step);
            } else {
                el.textContent = String(target);
            }
        }

        window.requestAnimationFrame(step);
    }

    function initCounters() {
        var counters = document.querySelectorAll(".sz-count-up");
        if (!counters.length) return;

        if (prefersReduced) {
            counters.forEach(function (el) {
                var target = parseInt(el.getAttribute("data-target"), 10);
                if (!isNaN(target)) el.textContent = String(target);
            });
            return;
        }

        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    var el = entry.target;
                    if (el.dataset.counted === "1") return;
                    el.dataset.counted = "1";
                    var target = parseInt(el.getAttribute("data-target"), 10);
                    if (!isNaN(target)) animateCounter(el, target, 1200);
                    observer.unobserve(el);
                });
            },
            { threshold: 0.4 }
        );

        counters.forEach(function (el) { observer.observe(el); });
    }

    function initHeaderScroll() {
        var header = document.getElementById("szLandingHeader");
        if (!header) return;
        window.addEventListener("scroll", function () {
            header.classList.toggle("scrolled", window.scrollY > 12);
        }, { passive: true });
    }

    function boot() {
        initReveal();
        initSmoothAnchors();
        initCounters();
        initHeaderScroll();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();
