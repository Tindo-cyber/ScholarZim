/**
 * Stops the browser's back/forward cache from resurrecting a page that must be
 * re-checked with the server, for every role.
 *
 * Authenticated pages and the sign-in page are served `Cache-Control: no-store`,
 * which keeps them out of the HTTP cache. Some browsers can still restore a
 * page from the back/forward cache (bfcache) without making any request at all
 * - and then Back after signing out would show the old dashboard, and Back to
 * /login after signing in would show a form while the session is still live,
 * never reaching the server rule that ends it.
 *
 * Only pages that opt in with `<html data-sz-sensitive>` are touched (the
 * signed-in shell and the sign-in layout); public pages keep the bfcache. A page
 * restored from it is reloaded instead, which is an ordinary request: a signed-out
 * visitor is sent to /login, and /login ends any live session.
 */
(() => {
    'use strict';

    window.addEventListener('pageshow', (event) => {
        if (event.persisted && document.documentElement.hasAttribute('data-sz-sensitive')) {
            window.location.reload();
        }
    });
})();
