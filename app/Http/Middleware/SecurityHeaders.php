<?php

namespace App\Http\Middleware;

use App\Support\FrontendAssets;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response hardening headers, carried over from the Spring app's SecurityConfig.
 *
 * The Blade views ship no inline <script> at all — every asset is self-hosted
 * under public/assets or bundled by Vite — so script-src can stay on plain
 * 'self' without the per-request nonce the Thymeleaf templates needed. Inline
 * style="..." attributes are still used across the views, so style-src keeps
 * 'unsafe-inline' until those are extracted.
 *
 * That first sentence is load-bearing, and it stopped being true once without
 * anybody noticing: an inline <script> was added to the subject-requirements
 * grid, the browser refused to run it, and a provider silently could not
 * attach a requirement to a listing. It is now back to being true, and
 * SecurityHeadersTest asserts it stays that way rather than leaving it to
 * whoever reads this comment.
 */
class SecurityHeaders
{
    /**
     * The policy, and the only one a deployed instance ever sends.
     *
     * Nothing below relaxes this. The development block adds one extra origin
     * and only to a local machine that is running `npm run dev`; see
     * developmentCsp().
     */
    private const CSP = "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "font-src 'self' data:; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "form-action 'self'; "
        . "base-uri 'self'; "
        . "frame-ancestors 'self'; "
        . "object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->csp());
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // HSTS only means anything over TLS, and announcing it from a plain-HTTP
        // dev server would pin developers to https://localhost.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * PRODUCTION: the constant above, unchanged, and the only branch a deployed
     * instance can take.
     *
     * DEVELOPMENT: the same policy plus the Vite dev server's origin, and only
     * when both of these hold - the app is running in the `local` environment,
     * and something is genuinely listening at the address in public/hot.
     * Neither is true of a deployment: APP_ENV is production, and there is no
     * hot file in the image.
     */
    private function csp(): string
    {
        if (! app()->environment('local')) {
            return self::CSP;
        }

        $origin = FrontendAssets::devServerOrigin();

        return $origin === null ? self::CSP : $this->developmentCsp($origin);
    }

    /**
     * The development policy: strict, plus one named origin.
     *
     * `npm run dev` has @vite() emit <script> and <link> tags pointing at the
     * Vite dev server rather than at public/build, and that server is a
     * different origin - http://[::1]:5173 or wherever it landed. Under
     * `script-src 'self'` the browser blocks every one of them, so a developer
     * running the dev server gets the vendor theme with none of ScholarZim's
     * own CSS or JavaScript: a page styled enough to look deliberate and broken
     * enough to be useless. Worse, it is quietly a different page from the one
     * that deploys, so a UI reviewed that way was never actually reviewed.
     *
     * What is added is the exact origin read out of the hot file, and nothing
     * else. Not 'unsafe-inline', not 'unsafe-eval', not a wildcard. The three
     * directives it lands in are the three the dev server needs: the module
     * graph (script-src), the injected stylesheets (style-src), and the
     * hot-reload socket (connect-src, over ws: as well as http:).
     */
    private function developmentCsp(string $origin): string
    {
        $socket = str_starts_with($origin, 'https://')
            ? 'wss://' . substr($origin, 8)
            : 'ws://' . substr($origin, 7);

        return str_replace(
            [
                "script-src 'self'; ",
                "style-src 'self' 'unsafe-inline'; ",
                "connect-src 'self'; ",
            ],
            [
                "script-src 'self' {$origin}; ",
                "style-src 'self' 'unsafe-inline' {$origin}; ",
                "connect-src 'self' {$origin} {$socket}; ",
            ],
            self::CSP
        );
    }
}
