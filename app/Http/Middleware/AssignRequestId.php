<?php

namespace App\Http\Middleware;

use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id, and puts it where everything downstream can find it.
 *
 * Three places, for three audiences: Log::withContext so every line written
 * during this request carries it, RequestContext so services and the audit trail
 * can reach it without being handed a Request they otherwise have no use for,
 * and a response header so whoever reported the problem can quote the id from
 * their own browser.
 *
 * An id already on the request is adopted rather than replaced, so a trace that
 * started at the proxy carries through instead of restarting at the application
 * boundary.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = RequestContext::adopt($request->header(RequestContext::HEADER));

        RequestContext::setClient($request->ip(), $request->userAgent());

        // Every subsequent Log::* call in this request carries these without the
        // caller having to remember to pass them.
        Log::withContext(RequestContext::forLogging());

        $response = $next($request);

        $response->headers->set(RequestContext::HEADER, $id);

        return $response;
    }
}
