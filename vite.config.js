import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

/**
 * Only ScholarZim's own CSS and JS go through the bundler, so they are minified
 * and content-hashed - a deploy can no longer serve a stale stylesheet from a
 * browser cache. The BVite vendor theme stays a static file in public/assets:
 * it ships compiled and is otherwise not edited here, the one documented
 * exception being the removal of its remote @imports (see partials/assets).
 */
export default defineConfig({
    /*
     * The dev server binds to 127.0.0.1 rather than Vite's default `localhost`.
     *
     * Node resolves localhost to ::1 first, so the dev server came up on
     * http://[::1]:5173 and wrote that into public/hot. A Content-Security-
     * Policy source expression cannot express a bracketed IPv6 literal - Chrome
     * answers "contains an invalid source ... It will be ignored" and drops the
     * whole entry - so there is no way to allow that origin without opening the
     * policy far wider than one dev server deserves.
     *
     * 127.0.0.1 is the same loopback interface, is what `artisan serve` already
     * uses, and is a host CSP is happy to name. See
     * App\Http\Middleware\SecurityHeaders::developmentCsp().
     */
    server: {
        host: '127.0.0.1',
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: ['resources/views/**'],
        }),
    ],
});
