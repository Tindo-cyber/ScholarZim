<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response hardening headers, carried over from the Spring app's SecurityConfig.
 *
 * The Blade views ship no inline <script> at all — every asset is self-hosted
 * under public/assets — so script-src can stay on plain 'self' without the
 * per-request nonce the Thymeleaf templates needed. Inline style="..."
 * attributes are still used across the views, so style-src keeps
 * 'unsafe-inline' until those are extracted.
 */
class SecurityHeaders
{
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

        $response->headers->set('Content-Security-Policy', self::CSP);
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
}
