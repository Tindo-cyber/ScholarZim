<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Scholarships for Zimbabwean students') &middot; ScholarZim</title>
    <meta name="description" content="@yield('meta_description', 'ScholarZim matches Zimbabwean students with scholarships they actually qualify for.')">

    @include('partials.assets')
    @stack('styles')
</head>
<body data-bvite="theme-Mariner" class="layout-border svgstroke-a sz-public">

{{-- First tab stop on every page: keyboard users skip the nav rather than tabbing through it. --}}
<a class="sz-skip-link" href="#sz-main-content">Skip to main content</a>

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
            {{--
                No nav-link items left here: the brand mark above already
                links home (the near-universal convention), and
                Browse/ScholarFit/providers are the homepage's own hero CTAs
                and sections, reachable from every page through the footer
                below. ms-auto on the actions replaces what mx-auto on a now-
                empty <ul> used to do, so Sign in / Create account stay
                pinned to the right rather than drifting to centre.
            --}}
            <div class="sz-public-nav-actions d-flex flex-wrap align-items-center gap-2 ms-auto">
                <x-install-app />
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

<main id="sz-main-content" tabindex="-1">
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
                    Scholarship discovery and applications for Zimbabwean students, with eligibility
                    that explains itself.
                </p>
            </div>
            <div class="col-6 col-lg-2">
                {{-- h2 carrying the h6 type scale. These are the footer's own
                     section headings; marking them h6 to get the size dropped
                     three levels below the page's last heading, on every public
                     page. The class keeps the appearance identical. --}}
                <h2 class="h6 fw-semibold mb-3">Students</h2>
                <ul class="list-unstyled d-grid gap-2 mb-0">
                    <li><a class="link-secondary text-decoration-none" href="{{ route('scholarships.index') }}">Browse scholarships</a></li>
                    <li><a class="link-secondary text-decoration-none" href="{{ route('scholarfit') }}">How ScholarFit works</a></li>
                    <li><a class="link-secondary text-decoration-none" href="{{ route('register') }}">Create account</a></li>
                    <li><a class="link-secondary text-decoration-none" href="{{ route('login') }}">Sign in</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h2 class="h6 fw-semibold mb-3">Providers</h2>
                <ul class="list-unstyled d-grid gap-2 mb-0">
                    <li><a class="link-secondary text-decoration-none" href="{{ route('register.provider') }}">Register</a></li>
                    <li><a class="link-secondary text-decoration-none" href="{{ route('login') }}">Provider sign in</a></li>
                </ul>
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
