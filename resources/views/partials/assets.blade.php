{{--
    Every stylesheet and script the document head needs, for all four layouts.

    This is one partial rather than a block each layout repeats because the
    repetition had already drifted: the error layout pointed at a
    public/assets/css/scholarzim.css that does not exist, so every 404 and 500
    lost ScholarZim's own styles, and the auth layout was the only one without
    theme-toggle.js, so signing in ignored a reader's saved dark mode. Neither is
    visible on the page that introduces it - only on the one page nobody reloads
    while working - so the fix is to leave nothing for a layout to get wrong.

    Order matters: the vendor theme first, ScholarZim's overlay second. Both
    define plain `main`, and the overlay has to win - see the app-shell comment
    in resources/css/scholarzim.css.
--}}
<link rel="icon" href="{{ asset('assets/img/favicon.ico') }}">
<link rel="stylesheet" href="{{ asset('assets/bvite/css/bvite-base.css') }}">
<link rel="stylesheet" href="{{ asset('assets/bvite/css/bvite-theme.css') }}">

{{--
    Installable-app metadata. It lives here, in the one partial every layout
    includes, for the same reason the stylesheets do: a browser only offers to
    install a site whose manifest it found, and a layout that quietly omitted the
    link would be installable everywhere except on the page a reader happened to
    be on when they went looking for the button.

    theme-color paints the Android status bar and the splash screen; the two
    apple-mobile-web-app-* lines are what make an iOS home-screen launch open
    without Safari's chrome, since iOS reads those rather than the manifest's
    `display`. apple-touch-icon is likewise iOS-only - it ignores the manifest
    icons - so it is the one icon that has to be named in the head.
--}}
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="theme-color" content="{{ \App\Support\Pwa::THEME_COLOR }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="ScholarZim">
<link rel="apple-touch-icon" href="{{ asset('assets/img/apple-touch-icon.png') }}">

{{--
    ScholarZim's own CSS and JS, bundled by Vite so they are minified and
    content-hashed - a deploy can no longer serve a stale stylesheet out of a
    browser cache. The BVite vendor theme above ships compiled and is never
    edited here, so it has nothing to gain from a build step.

    The fallback matters. @vite() throws when no build has been made, which would
    turn a forgotten `npm run build` into a 500 on every page. Without a manifest
    the source files are served individually instead, by SourceAssetController -
    unhashed and unminified, but a working site. FrontendAssets makes that call;
    see it for why the presence of public/hot or of a manifest is not on its own
    enough to trust either one.
--}}
@if(\App\Support\FrontendAssets::viteReady())
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@else
    <link rel="stylesheet" href="{{ route('assets.source', 'scholarzim.css') }}">
    @foreach(\App\Http\Controllers\SourceAssetController::FALLBACK_SCRIPTS as $script)
        <script src="{{ route('assets.source', $script) }}" defer></script>
    @endforeach
@endif

{{--
    theme-toggle.js is deliberately outside the bundle: it has to run before
    first paint to avoid a flash of the wrong theme, so it stays a
    render-blocking script here rather than a deferred module. It guards on the
    toggle elements it needs, so it is safe on the auth and error pages that
    have no toggle to show.
--}}
<script src="{{ asset('assets/js/theme-toggle.js') }}"></script>
