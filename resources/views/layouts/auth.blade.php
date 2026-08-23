<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') &middot; ScholarZim</title>

    <link rel="icon" href="{{ asset('assets/img/favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('assets/bvite/css/bvite-base.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/bvite/css/bvite-theme.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/scholarzim.css') }}">
    @stack('styles')
</head>
<body data-bvite="theme-Mariner" class="layout-border svgstroke-a sz-auth">

<main class="container-fluid px-0">
    <div class="row g-0 min-vh-100">

        {{-- Brand rail: hidden on small screens so the form gets the full width. --}}
        <div class="col-lg-5 d-none d-lg-flex flex-column justify-content-between sz-auth-aside p-5 text-white">
            <a class="d-flex align-items-center gap-2 fw-bold text-white text-decoration-none" href="{{ route('home') }}">
                <x-brand-mark tone="light" />
                <span class="fs-5">ScholarZim</span>
            </a>

            <div>
                <h1 class="h2 fw-bold mb-3">@yield('aside_heading', 'Find scholarships you actually qualify for.')</h1>
                <p class="opacity-75 mb-4">
                    @yield('aside_copy', 'ScholarFit scores every listing against your profile and shows you exactly which criteria you meet — and which ones to fix.')
                </p>

                @hasSection('aside_steps')
                    @yield('aside_steps')
                @else
                    <ul class="list-unstyled d-grid gap-3 mb-0">
                        <li class="d-flex gap-3">
                            <span class="sz-auth-tick">1</span>
                            <span>Build your academic profile once.</span>
                        </li>
                        <li class="d-flex gap-3">
                            <span class="sz-auth-tick">2</span>
                            <span>Get ranked matches with a transparent score.</span>
                        </li>
                        <li class="d-flex gap-3">
                            <span class="sz-auth-tick">3</span>
                            <span>Apply and track every decision in one place.</span>
                        </li>
                    </ul>
                @endif
            </div>

            <p class="opacity-50 small mb-0">&copy; {{ date('Y') }} ScholarZim</p>
        </div>

        <div class="col-lg-7 d-flex align-items-center justify-content-center p-4 p-lg-5">
            <div class="w-100" style="max-width: 32rem;">
                <a class="d-lg-none d-inline-flex align-items-center gap-2 fw-bold text-decoration-none mb-4"
                   href="{{ route('home') }}">
                    <x-brand-mark />
                    <span>ScholarZim</span>
                </a>

                <x-flash-alerts />

                @yield('content')
            </div>
        </div>
    </div>
</main>

<script src="{{ asset('assets/bvite/js/bvite.js') }}" type="module"></script>
<script src="{{ asset('assets/js/scholarzim.js') }}" defer></script>
@stack('scripts')
</body>
</html>
