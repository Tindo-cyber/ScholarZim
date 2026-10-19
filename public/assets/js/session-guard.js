/**
 * Closing a page ends the sign-in.
 *
 * A server cannot see a tab or a window close, and a "session cookie" only goes when the whole browser does: close the
 * page, open it again, and the old sign-in is still there. This is the part that runs in the browser.
 *
 * Each tab that has been used for ScholarZim carries a marker in sessionStorage, which the browser throws away when
 * the tab closes. A signed-in page in a tab with no marker is therefore a page that was opened after the last one
 * closed - or a new tab beside ones still open. Telling those apart is a question to the other tabs over a
 * BroadcastChannel: any that is open answers. No answer means every ScholarZim page was closed, so this one goes to
 * the sign-in page, where the server ends the session (the sign-in page always does) and asks for the password.
 *
 * The page stays hidden while it asks, so a protected page is never drawn for a person who should have been signed out.
 * A signed-out page marks its tab, so signing in and moving around never asks again. If sessionStorage or
 * BroadcastChannel is unavailable nothing happens and the server's own rules apply.
 *
 * Deliberately not part of the Vite bundle: it has to run before the page is first drawn, so it is a plain script in the
 * head, the same as theme-toggle.js. It is a convenience layered on the server's rules (the cookie still ends with the
 * browser, and the session still expires when idle), not a replacement for them. A browser that restores tabs with their
 * storage ("continue where you left off", Ctrl+Shift+T) restores the marker with them.
 */
(function () {
    'use strict';

    var KEY = 'sz-tab';
    var ASK_MS = 500;
    var root = document.documentElement;
    var signedIn = root.getAttribute('data-sz-auth') === '1';
    var store;
    var channel;

    try {
        store = window.sessionStorage;
        store.getItem(KEY);
        channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel('sz-tabs');
    } catch (e) {
        return;
    }

    if (!signedIn) {
        // A signed-out page: whatever signs in next, signs in in this tab.
        try { store.setItem(KEY, '1'); } catch (e) { /* storage full or blocked: nothing to guard with */ }
    }

    if (channel === null) {
        return;
    }

    // Every tab that has been used answers, so a new tab beside one that is still open stays signed in.
    channel.addEventListener('message', function (event) {
        if (event.data === 'ping' && store.getItem(KEY)) {
            channel.postMessage('alive');
        }
    });

    if (!signedIn || store.getItem(KEY)) {
        return;
    }

    var answered = false;

    root.style.visibility = 'hidden';

    channel.addEventListener('message', function (event) {
        if (event.data === 'alive') {
            answered = true;
        }
    });

    channel.postMessage('ping');

    window.setTimeout(function () {
        if (answered) {
            try { store.setItem(KEY, '1'); } catch (e) { /* see above */ }
            root.style.visibility = '';

            return;
        }

        // Nothing else of ours is open: this page was opened after the last one closed.
        window.location.replace(root.getAttribute('data-sz-login') || '/login');
    }, ASK_MS);
})();
