<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Scholarships for Zimbabwean students') &middot; ScholarZim</title>
    <meta name="description" content="@yield('meta_description', 'ScholarZim matches Zimbabwean students with scholarships they actually qualify for.')">

    <link rel="icon" href="{{ asset('assets/img/favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('assets/bvite/css/bvite-base.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/bvite/css/bvite-theme.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/scholarzim.css') }}">
    <script src="{{ asset('assets/js/theme-toggle.js') }}"></script>
    @stack('styles')
</head>
<body data-bvite="theme-Mariner" class="layout-border svgstroke-a sz-public">

<header class="sz-public-nav border-bottom sticky-top bg-body">
    <nav class="container navbar navbar-expand-lg py-3">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="{{ route('home') }}">
            <x-brand-mark />
            <span>Scholar<span class="text-primary">Zim</span></span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#szPublicNav"
                aria-controls="szPublicNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="szPublicNav">
            <ul class="navbar-nav mx-auto gap-lg-2">
                <li class="nav-item">
                    <a class="nav-link @active(request()->routeIs('home'))" href="{{ route('home') }}">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link @active(request()->routeIs('scholarships.*'))" href="{{ route('scholarships.index') }}">Browse scholarships</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('home') }}#how-it-works">How it works</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('register.provider') }}">For providers</a>
                </li>
            </ul>

            <div class="d-flex flex-wrap align-items-center gap-2">
                <x-theme-toggle />

                @auth
                    <a class="btn btn-primary" href="{{ route('dashboard') }}">Go to dashboard</a>
                @else
                    <a class="btn btn-link text-body text-decoration-none" href="{{ route('login') }}">Sign in</a>
                    <a class="btn btn-primary" href="{{ route('register') }}">Create free account</a>
                @endauth
            </div>
        </div>
    </nav>
</header>

<main>
    <div class="container pt-3">
        <x-flash-alerts />
    </div>

    @yield('content')
</main>

<footer class="sz-public-footer border-top mt-5 py-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="d-flex align-items-center gap-2 fw-bold mb-2">
                    <x-brand-mark />
                    <span>Scholar<span class="text-primary">Zim</span></span>
                </div>
                <p class="text-secondary mb-0">
                    Scholarship discovery and applications for Zimbabwean students, with a match score
                    that explains itself.
                </p>
            </div>
            <div class="col-6 col-lg-2">
                <h6 class="fw-semibold mb-3">Students</h6>
                <ul class="list-unstyled d-grid gap-2 mb-0">
                    <li><a class="link-secondary text-decoration-none" href="{{ route('scholarships.index') }}">Browse</a></li>
                    <li><a class="link-secondary text-decoration-none" href="{{ route('register') }}">Create account</a></li>
                    <li><a class="link-secondary text-decoration-none" href="{{ route('login') }}">Sign in</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h6 class="fw-semibold mb-3">Providers</h6>
                <ul class="list-unstyled d-grid gap-2 mb-0">
                    <li><a class="link-secondary text-decoration-none" href="{{ route('register.provider') }}">Register</a></li>
                    <li><a class="link-secondary text-decoration-none" href="{{ route('login') }}">Provider sign in</a></li>
                </ul>
            </div>
            <div class="col-lg-4">
                <h6 class="fw-semibold mb-3">Developers</h6>
                <p class="text-secondary mb-2">Public listings are available as JSON.</p>
                <code class="small">GET /api/public/scholarships</code>
            </div>
        </div>

        <hr class="my-4">
        <p class="text-secondary small mb-0">&copy; {{ date('Y') }} ScholarZim. All rights reserved.</p>
    </div>
</footer>

<script src="{{ asset('assets/bvite/js/bvite.js') }}" type="module"></script>
@stack('scripts')
</body>
</html>
