<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') &middot; ScholarZim</title>
    @include('partials.assets')
</head>
<body data-bvite="theme-Mariner" class="layout-border svgstroke-a">

<main class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="text-center sz-error-panel">
        <a class="d-inline-flex align-items-center gap-2 fw-bold text-decoration-none mb-4" href="{{ url('/') }}">
            <x-brand-mark />
            <span>Scholar<span class="text-primary">Zim</span></span>
        </a>

        {{-- The number is decoration over the sentence below it; the sentence is
             the message, so the number is not announced twice. --}}
        <p class="display-1 fw-bold text-primary mb-0" aria-hidden="true">@yield('code')</p>
        <h1 class="h4 fw-bold mb-2">@yield('heading')</h1>
        <p class="text-secondary mb-4">@yield('message')</p>

        {{--
            The way out, chosen per status rather than shared.

            Every page used to offer "Back to home" and "Browse scholarships",
            which is the right pair for a dead link and the wrong pair for an
            expired session - the one thing that fixes a 419 is signing in
            again, and the page said so in its message while offering no way to
            do it. No auth() call here on purpose: this layout also renders
            while the application is down for maintenance, where there is no
            session to ask.
        --}}
        <div class="d-flex flex-wrap gap-2 justify-content-center">
            @hasSection('actions')
                @yield('actions')
            @else
                <a class="btn btn-primary" href="{{ url('/') }}">Back to home</a>
                <a class="btn btn-outline-secondary" href="{{ url('/scholarships') }}">Browse scholarships</a>
            @endif
        </div>
    </div>
</main>

</body>
</html>
