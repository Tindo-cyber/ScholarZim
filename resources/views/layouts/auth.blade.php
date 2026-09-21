<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') &middot; ScholarZim</title>

    @include('partials.assets')
    @stack('styles')
</head>
<body data-bvite="theme-Mariner" class="layout-border svgstroke-a sz-auth">

{{-- First tab stop on every page: keyboard users skip the nav rather than tabbing through it. --}}
<a class="sz-skip-link" href="#sz-main-content">Skip to main content</a>

<main id="sz-main-content" tabindex="-1" class="container-fluid px-0">
    <div class="row g-0 min-vh-100">

        {{-- Brand rail: hidden on small screens so the form gets the full width. --}}
        <div class="col-lg-5 d-none d-lg-flex flex-column justify-content-between sz-auth-aside p-5 text-white">
            <a class="d-flex align-items-center gap-2 fw-bold text-white text-decoration-none" href="{{ route('home') }}">
                <x-brand-mark tone="light" />
                <span class="fs-5">ScholarZim</span>
            </a>

            <div>
                {{--
                    A <p>, not an <h1>.

                    Every auth page already has one: the form's own heading, which
                    is what the page is for. This rail repeats the brand promise
                    beside it, and marking it up as a second level-one heading gave
                    each of these pages two - so a screen-reader user listing
                    headings was told the page was about two different things, and
                    the one that mattered came second.
                --}}
                <p class="h2 fw-bold mb-3">@yield('aside_heading', 'Find scholarships you actually qualify for.')</p>
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
                {{--
                    The brand link doubles as the way back out to the public site,
                    and the theme control sits beside it: these pages already
                    honour a stored preference, but they were the one place in the
                    product offering no way to change it.
                --}}
                <div class="d-flex align-items-center justify-content-between gap-2 mb-4">
                    <a class="d-lg-none d-inline-flex align-items-center gap-2 fw-bold text-decoration-none"
                       href="{{ route('home') }}">
                        <x-brand-mark />
                        <span>ScholarZim</span>
                    </a>
                    <div class="ms-auto"><x-theme-toggle /></div>
                </div>

                <x-flash-alerts />

                @yield('content')
            </div>
        </div>
    </div>
</main>

<script src="{{ asset('assets/bvite/js/bvite.js') }}" type="module"></script>
@stack('scripts')
</body>
</html>
