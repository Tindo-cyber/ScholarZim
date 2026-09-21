/*
 * The authenticated shell's navigation: which item is lit, and the drawer.
 *
 * Both halves are progressive enhancement. Without this file the sidebar still
 * renders, still links, still shows a server-resolved active item, and the
 * drawer still opens - Bootstrap's own Offcanvas does that from the data
 * attributes in the markup. What is added here is the part the server cannot
 * do and Bootstrap does not do.
 */
(function () {
    'use strict';

    var SIDEBAR_ID = 'szSidebar';
    var DESKTOP = '(min-width: 1200px)'; // Bootstrap's xl, where the drawer becomes a sidebar.

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function isDesktop() {
        return window.matchMedia(DESKTOP).matches;
    }

    /* ---------------------------------------------------------------------
     * Fragment-aware active state
     *
     * Two nav items can address the same route and differ only by fragment:
     * "My profile" is /applicant/profile and "Documents" is
     * /applicant/profile#documents. A fragment never reaches the server, so
     * Blade cannot tell which of the two the reader is looking at - and when
     * both were given `request()->routeIs('applicant.profile')` they both lit
     * up at once, which is the defect this fixes.
     *
     * The server now marks only the fragment-less item, which is the honest
     * answer with no script running. Here we refine it: group every nav link by
     * the path it points at, and for the group matching the current path pick
     * the one whose fragment matches location.hash, falling back to the item
     * with no fragment. A group with one member is left exactly as rendered.
     * ------------------------------------------------------------------- */

    function navLinks() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-sz-nav-item]'));
    }

    function normalisePath(path) {
        return path.replace(/\/+$/, '') || '/';
    }

    function setActive(link, active) {
        link.classList.toggle('active', active);
        // `text-body` is what the component uses for the resting colour, so it
        // has to come off when the pill is filled and go back on when it is not.
        link.classList.toggle('text-body', !active);

        if (active) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    }

    function syncActiveItem() {
        var here = normalisePath(window.location.pathname);
        var hash = window.location.hash;

        var group = navLinks().filter(function (link) {
            return normalisePath(link.pathname) === here;
        });

        // One link for this path means nothing is ambiguous; trust the server.
        if (group.length < 2) {
            return;
        }

        var match = null;

        if (hash) {
            match = group.find(function (link) {
                return link.hash === hash;
            }) || null;
        }

        if (!match) {
            match = group.find(function (link) {
                return !link.hash;
            }) || null;
        }

        if (!match) {
            return;
        }

        group.forEach(function (link) {
            setActive(link, link === match);
        });
    }

    /* ---------------------------------------------------------------------
     * The drawer
     *
     * Bootstrap's Offcanvas already gives us the backdrop, Escape, the focus
     * trap and body scroll lock, even though this element is not a .offcanvas -
     * it drives everything off the `show`/`showing` classes that
     * resources/css/scholarzim.css styles. Three things it does not do:
     *
     *   1. Keep aria-expanded on the toggle in step. Offcanvas, unlike
     *      Collapse and Dropdown, never touches the trigger's state, so a
     *      screen reader was told the menu was closed while it was open.
     *
     *   2. Clear the aria-hidden it leaves behind. hide() sets aria-hidden on
     *      the panel, which is right while the panel is off-screen and wrong at
     *      xl and above, where the same element is a permanently visible
     *      sidebar. Open the drawer on a phone, close it, rotate to a tablet in
     *      landscape, and the whole navigation was hidden from assistive tech
     *      while plainly visible on screen.
     *
     *   3. Close itself when the viewport grows past the breakpoint. The CSS
     *      stops positioning the panel as a drawer, but the backdrop and the
     *      scroll lock stay, leaving the page dimmed and unscrollable.
     * ------------------------------------------------------------------- */

    function wireDrawer(sidebar) {
        var toggles = Array.prototype.slice.call(
            document.querySelectorAll('[data-bs-toggle="offcanvas"][data-bs-target="#' + SIDEBAR_ID + '"]')
        );

        /*
         * Close the drawer without reaching for window.bootstrap.
         *
         * The vendor bundle throws partway through loading - a broken dompurify
         * module inside assets/bvite/js/bvite.js - so it never gets as far as
         * assigning window.bootstrap. Bootstrap's own delegated data-API
         * listeners are registered before that point and work perfectly: the
         * toggle opens the drawer, Escape and the backdrop close it. Only the
         * programmatic API is missing.
         *
         * So this clicks the dismiss button that is already in the sidebar's
         * header, which is the same path a reader takes, and goes through the
         * same delegated listener. An earlier version called
         * Offcanvas.getInstance() behind a `window.bootstrap &&` guard, which
         * meant both of the behaviours below silently did nothing: tapping a
         * nav item left the drawer sitting over the section it had scrolled to,
         * and growing the window past xl left the backdrop up and the body
         * scroll-locked on a desktop page.
         */
        function closeDrawer() {
            if (window.bootstrap && window.bootstrap.Offcanvas) {
                var instance = window.bootstrap.Offcanvas.getInstance(sidebar);
                if (instance) {
                    instance.hide();
                    return;
                }
            }

            var dismiss = sidebar.querySelector('[data-bs-dismiss="offcanvas"]');
            if (dismiss) {
                dismiss.click();
            }
        }

        function announce(expanded) {
            toggles.forEach(function (toggle) {
                toggle.setAttribute('aria-expanded', String(expanded));
            });
        }

        function clearStaleAriaHidden() {
            if (isDesktop()) {
                sidebar.removeAttribute('aria-hidden');
            }
        }

        sidebar.addEventListener('show.bs.offcanvas', function () {
            announce(true);
        });

        sidebar.addEventListener('hidden.bs.offcanvas', function () {
            announce(false);
            clearStaleAriaHidden();
        });

        // A link inside the drawer navigates, so the drawer should not be left
        // open over the page that arrives - and when the link is a fragment on
        // the page we are already on, nothing navigates at all and the drawer
        // would simply stay open on top of the section it scrolled to.
        sidebar.addEventListener('click', function (event) {
            if (!event.target.closest('[data-sz-nav-item]') || isDesktop()) {
                return;
            }

            closeDrawer();
        });

        window.addEventListener('resize', function () {
            if (!isDesktop()) {
                return;
            }

            if (sidebar.classList.contains('show')) {
                closeDrawer();
            }

            clearStaleAriaHidden();
        });

        clearStaleAriaHidden();
    }

    ready(function () {
        var sidebar = document.getElementById(SIDEBAR_ID);

        // Every page outside the authenticated shell - the landing page, the
        // sign-in form, the error pages - has neither, so both guards matter.
        if (navLinks().length) {
            syncActiveItem();

            window.addEventListener('hashchange', syncActiveItem);
            window.addEventListener('popstate', syncActiveItem);
            // Back/forward out of the page cache restores the DOM as it was,
            // including an active item that may no longer match the URL.
            window.addEventListener('pageshow', syncActiveItem);
        }

        if (sidebar) {
            wireDrawer(sidebar);
        }
    });
})();
