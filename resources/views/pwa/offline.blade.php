{{--
    The offline fallback, served from the service worker's precache when a
    navigation cannot reach the server.

    Two constraints shape this page, and both come from the fact that it is
    stored on the device rather than fetched:

    1. It renders nothing that depends on who is signed in. One copy is kept per
       device and it outlives the session that fetched it, so a name, an
       application, or an unread count here would be shown to whoever picks the
       phone up next.

    2. It carries no inline script. The retry is a plain link back to the site
       root: offline it lands here again, online it goes home. That keeps the
       page working under the same script-src 'self' policy as every other page,
       rather than needing a CSP exception for a button that reloads.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline &middot; ScholarZim</title>

    @include('partials.assets')
</head>
<body data-bvite="theme-Mariner" class="layout-border svgstroke-a">

<main class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="text-center" style="max-width: 32rem;">
        <span class="d-inline-flex align-items-center gap-2 fw-bold mb-4">
            <x-brand-mark />
            <span>Scholar<span class="text-primary">Zim</span></span>
        </span>

        <h1 class="h4 fw-bold mb-2">You&rsquo;re offline</h1>
        <p class="text-secondary mb-4">
            Reconnect to the internet to continue using ScholarZim. Searching, applying and
            reviewing applications all need a connection &mdash; nothing is lost while you are away.
        </p>

        <a class="btn btn-primary" href="{{ url('/') }}">Try again</a>
    </div>
</main>

</body>
</html>
