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
    <div class="text-center" style="max-width: 32rem;">
        <a class="d-inline-flex align-items-center gap-2 fw-bold text-decoration-none mb-4" href="{{ url('/') }}">
            <x-brand-mark />
            <span>Scholar<span class="text-primary">Zim</span></span>
        </a>

        <p class="display-1 fw-bold text-primary mb-0">@yield('code')</p>
        <h1 class="h4 fw-bold mb-2">@yield('heading')</h1>
        <p class="text-secondary mb-4">@yield('message')</p>

        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <a class="btn btn-primary" href="{{ url('/') }}">Back to home</a>
            <a class="btn btn-outline-secondary" href="{{ url('/scholarships') }}">Browse scholarships</a>
        </div>
    </div>
</main>

</body>
</html>
