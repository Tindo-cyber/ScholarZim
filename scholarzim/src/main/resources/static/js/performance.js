/**
 * Core Web Vitals helpers — lazy images, layout stability.
 */
(function () {
    "use strict";

    function enhanceImages() {
        document.querySelectorAll("img:not([loading])").forEach(function (img) {
            if (img.getAttribute("fetchpriority") === "high") return;
            if (img.closest(".sz-home-hero__visual, .sz-auth-visual")) return;
            img.setAttribute("loading", "lazy");
        });

        document.querySelectorAll("img:not([decoding])").forEach(function (img) {
            img.setAttribute("decoding", "async");
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", enhanceImages);
    } else {
        enhanceImages();
    }
})();
