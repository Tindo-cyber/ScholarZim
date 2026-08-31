<?php

/*
|--------------------------------------------------------------------------
| Trusted Proxies
|--------------------------------------------------------------------------
|
| Read by Illuminate\Http\Middleware\TrustProxies when the middleware's own
| $proxies property is unset, which is the case for App\Http\Middleware\
| TrustProxies. Nothing is trusted unless TRUSTED_PROXIES says so, so the
| default here is the behaviour the app already had.
|
| Why it needs setting in production. TLS is terminated by the reverse proxy
| in front of the container (see docker-compose.prod.yml), so the app only
| ever sees plain HTTP and learns the real scheme from X-Forwarded-Proto.
| With no trusted proxy that header is discarded, $request->secure() stays
| false on every request, and App\Http\Middleware\SecurityHeaders - which
| gates HSTS on exactly that check - never emits Strict-Transport-Security
| on a site that is served entirely over TLS. Generated absolute URLs have
| the same problem for the same reason.
|
| "*" trusts whichever host forwarded the request. That is the right answer
| only while the container is unreachable except through the proxy: the prod
| compose file publishes the app on 127.0.0.1 alone, so the proxy is the only
| thing that can connect. Publish that port more widely and this must become
| the proxy's actual address, or a client could spoof its own scheme and
| address by sending the X-Forwarded-* headers itself.
|
*/

return [

    'proxies' => env('TRUSTED_PROXIES'),

];
