import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

/**
 * Only ScholarZim's own CSS and JS go through the bundler, so they are minified
 * and content-hashed - a deploy can no longer serve a stale stylesheet from a
 * browser cache. The BVite vendor theme stays a static file in public/assets:
 * it ships compiled and is never edited here.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: ['resources/views/**'],
        }),
    ],
});
