<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') &middot; ScholarZim</title>

    @include('partials.assets')
    @stack('styles')
</head>
<body data-bvite="theme-Mariner" class="layout-border svgstroke-a layout-default">

{{-- First tab stop on every page: keyboard users skip the nav rather than tabbing through it. --}}
<a class="sz-skip-link" href="#sz-main-content">Skip to main content</a>

<main id="sz-main-content" tabindex="-1" class="container-fluid px-0">
    <div class="d-flex flex-column flex-xl-row">

        @include('partials.sidebar')

        {{--
            min-w-0, not min-vw-0. There is no .min-vw-0 in Bootstrap, in the
            theme, or in this stylesheet, so the class did nothing and this
            flex item kept min-width: auto - meaning it could not shrink below
            the widest thing inside it. One unbreakable string anywhere on any
            authenticated page therefore widened the whole column, dragging the
            sidebar, the topbar and the footer sideways with it instead of
            letting the .table-responsive that contains it scroll on its own.

            The audit log is where it showed: SettingsService records a weights
            change as 'ScholarFit weights set to ' . json_encode($weights), and
            that JSON has no space in it to break at.
        --}}
        <div class="sz-main flex-grow-1 min-w-0">
            @include('partials.topbar')

            {{-- .sz-page owns the page gutter, the vertical padding and the content
                 cap, so no view repeats them and none of them can drift apart. --}}
            <div class="sz-page">
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
