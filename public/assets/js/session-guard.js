/**
 * Closing a page ends the sign-in.
 *
 * A server cannot see a tab or a window close, and a "session cookie" only goes when the whole browser does: close the
 * page, open it again, and the old sign-in is still there. This is the part that runs in the browser.
 *
 * Two things say a signed-in page is NOT a continuation of what the person was doing:
 *
 *   1. Its tab has no marker. Each tab used for ScholarZim carries one in sessionStorage, which the browser throws away
 *      when the tab closes, so a signed-in page in a tab without one was opened after the last one closed - or is a new tab
 *      beside ones still open.
 *   2. Every ScholarZim page has been silent for a long time. Pages stamp the time in localStorage as they live and as they
 *      leave, so a long gap means all of them were gone: the browser was closed and its tabs restored, with their
 *      sessionStorage, by "continue where you left off" or Ctrl+Shift+T. (Moving between pages leaves a fresh stamp, so
 *      an ordinary navigation, a reload or a sleeping laptop never looks like this.)
 *
 * Either way the page asks the other open tabs over a BroadcastChannel. A tab that is itself confirmed answers; a tab that
 * is still asking does not, so restored tabs do not vouch for each other. No answer means no ScholarZim page was alive, and
 * this one goes to the sign-in page, where the server ends the session (the sign-in page always does) and asks for the password.
 *
 * The page stays hidden while it asks, so a protected page is never drawn for a person who should have been signed out.
 * A signed-out page marks its tab, so signing in and moving around never asks again. If sessionStorage, localStorage or
 * BroadcastChannel is unavailable nothing happens and the server's own rules apply.
 *
 * Deliberately not part of the Vite bundle: it has to run before the page is first drawn, so it is a plain script in the
 * head, the same as theme-toggle.js. It is a convenience layered on the server's rules (the cookie still ends with the
 * browser, and the session still expires when idle), not a replacement for them.
 */
(function () {
    'use strict';

    var KEY = 'sz-tab';
    var SEEN = 'sz-seen';
    var ASK_MS = 500;
    var BEAT_MS = 2000;
    var GONE_MS = 30000;
    var root = document.documentElement;
    var signedIn = root.getAttribute('data-sz-auth') === '1';
    var tab;
    var seen;
    var channel;

    try {
        tab = window.sessionStorage;
        seen = window.localStorage;
        tab.getItem(KEY);
        seen.getItem(SEEN);
        channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel('sz-tabs');
    } catch (e) {
        return;
    }

    function set(store, key, value) {
        try { store.setItem(key, value); } catch (e) { /* storage full or blocked: nothing to guard with */ }
    }

    var lastSeen = Number(seen.getItem(SEEN) || 0);
    var gone = lastSeen > 0 && Date.now() - lastSeen > GONE_MS;
    var confirmed = false;

    // A confirmed page that is alive (or leaving) says so; the gap it is measuring was read above. A page still asking stays
    // silent, so pages restored together cannot make each other look alive.
    function beat() {
        if (confirmed) {
            set(seen, SEEN, String(Date.now()));
        }
    }
    window.addEventListener('pagehide', beat);
    window.setInterval(beat, BEAT_MS);

    if (!signedIn) {
        // A signed-out page: whatever signs in next, signs in in this tab.
        set(tab, KEY, '1');
        confirmed = true;
        beat();
    }

    if (channel === null) {
        // Nothing to ask with: leave it to the server's rules.
        confirmed = true;
        beat();

        return;
    }

    // A confirmed tab answers, so a new tab beside one that is still open stays signed in. A tab still asking does not.
    channel.addEventListener('message', function (event) {
        if (event.data === 'ping' && confirmed) {
            channel.postMessage('alive');
        }
    });

    if (!signedIn) {
        return;
    }

    if (tab.getItem(KEY) && !gone) {
        confirmed = true;
        beat();

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
            confirmed = true;
            set(tab, KEY, '1');
            beat();
            root.style.visibility = '';

            return;
        }

        // Nothing else of ours is alive: this page was opened after the last one closed.
        window.location.replace(root.getAttribute('data-sz-login') || '/login');
    }, ASK_MS);
})();
