<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') &middot; ScholarZim</title>

    <link rel="icon" href="{{ asset('assets/img/favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('assets/bvite/css/bvite-base.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/bvite/css/bvite-theme.css') }}">
    @include('partials.assets')
    <script src="{{ asset('assets/js/theme-toggle.js') }}"></script>
    @stack('styles')
</head>
<body data-bvite="theme-Mariner" class="layout-border svgstroke-a layout-default">

{{-- First tab stop on every page: keyboard users skip the nav rather than tabbing through it. --}}
<a class="sz-skip-link" href="#sz-main-content">Skip to main content</a>

<main id="sz-main-content" tabindex="-1" class="container-fluid px-0">
    <div class="d-flex flex-column flex-xl-row">

        @include('partials.sidebar')

        <div class="sz-main flex-grow-1 min-vw-0">
            @include('partials.topbar')

            <div class="px-3 px-lg-4 py-4">
                <x-flash-alerts />

                @unless(auth()->user()->email_verified)
                    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 justify-content-between">
                        <span>Your email address is not verified yet. Some features stay locked until it is.</span>
                        <form method="POST" action="{{ route('verification.resend') }}" class="m-0">
                            @csrf
                            <button class="btn btn-sm btn-warning" type="submit">Resend verification email</button>
                        </form>
                    </div>
                @endunless

                @yield('content')
            </div>

            @include('partials.footer')
        </div>
    </div>
</main>

<script src="{{ asset('assets/bvite/js/bvite.js') }}" type="module"></script>
@stack('scripts')
</body>
</html>
