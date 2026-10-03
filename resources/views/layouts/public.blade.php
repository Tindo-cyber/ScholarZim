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
<body data-bvite="theme-Mariner" class="layout-border svgstroke-a sz-public @yield('body_class')">

{{-- First tab stop on every page: keyboard users skip the nav rather than tabbing through it. --}}
<a class="sz-skip-link" href="#sz-main-content">Skip to main content</a>

{{--
    One header, two presentations. Every public page renders this same
    markup - same logo, same nav items, same actions, same theme toggle -
    and only the landing page (body_class="sz-landing" below) gets the
    overlay treatment via CSS scoped to that class (.sz-landing
    .sz-public-nav in scholarzim.css): transparent/frosted, white text,
    absolutely positioned over the hero photo instead of pushing it down.
    Every other public page keeps the ordinary solid bar. Nothing here is
    hidden or swapped out for the landing page - see the theme toggle and
    install-app button below, both unconditional.
--}}
<header class="sz-public-nav @yield('body_class') border-bottom sticky-top bg-body">
    <nav class="container navbar navbar-expand-lg py-3">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="{{ route('home') }}">
            {{-- tone="light" on the landing page only - the same prop the auth layout's
                 own dark brand rail already uses for a white mark on a dark background. --}}
            <x-brand-mark :tone="request()->routeIs('home') ? 'light' : 'primary'" />
            <span>Scholar<span class="text-primary">Zim</span></span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#szPublicNav"
                aria-controls="szPublicNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="szPublicNav">
            {{--
                About is deliberately absent: there is no real page for it
                yet, and every nav item must be a route that actually
                exists rather than one invented to match a reference. The
                other three are real routes; "How It Works" is the
                dedicated process page, not the ScholarFit explanation
                page (that one stays reachable from the footer).
            --}}
            <ul class="navbar-nav gap-lg-2">
                <li class="nav-item">
                    <a class="nav-link @active(request()->routeIs('home'))" href="{{ route('home') }}">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link @active(request()->routeIs('scholarships.*'))" href="{{ route('scholarships.index') }}">Find Scholarships</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link @active(request()->routeIs('how-it-works'))" href="{{ route('how-it-works') }}">How It Works</a>
                </li>
            </ul>

            <div class="sz-public-nav-actions d-flex flex-wrap align-items-center gap-2 ms-auto">
                {{-- Install app keeps its normal blue outline everywhere except the landing
                     page's transparent bar, where that outline would be invisible - same
                     component and the same working PWA feature either way, just the
                     existing `class` prop pointed at a lighter variant. --}}
                <x-install-app :class="request()->routeIs('home') ? 'btn btn-sm btn-outline-light' : 'btn btn-sm btn-outline-primary'" />
                <x-theme-toggle />

                @auth
                    <a class="btn btn-primary" href="{{ route('dashboard') }}">Go to dashboard</a>
                @else
                    <a class="btn btn-link text-body text-decoration-none" href="{{ route('login') }}">Sign in</a>
                    <a class="btn btn-primary" href="{{ route('register') }}">Get Started</a>
                @endauth
            </div>
        </div>
    </nav>
</header>

<main id="sz-main-content" tabindex="-1">
    {{-- No pt-3 here: flash-alerts.blade.php carries its own top padding,
         scoped to only render when there is actually something to show. --}}
    <div class="container">
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
