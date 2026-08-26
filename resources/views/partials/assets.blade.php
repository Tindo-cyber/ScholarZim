{{--
    ScholarZim's own CSS and JS, bundled by Vite so they are minified and
    content-hashed - a deploy can no longer serve a stale stylesheet out of a
    browser cache. The BVite vendor theme is linked separately by each layout:
    it ships compiled and is never edited here, so it has nothing to gain from
    a build step.

    The fallback matters. @vite() throws when no build has been made, which would
    turn a forgotten `npm run build` into a 500 on every page. Without a manifest
    the source files are served individually instead, by SourceAssetController -
    unhashed and unminified, but a working site.

    FrontendAssets makes that call; see it for why the presence of public/hot or
    of a manifest is not on its own enough to trust either one.
--}}
@if(\App\Support\FrontendAssets::viteReady())
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@else
    <link rel="stylesheet" href="{{ route('assets.source', 'scholarzim.css') }}">
    @foreach(\App\Http\Controllers\SourceAssetController::FALLBACK_SCRIPTS as $script)
        <script src="{{ route('assets.source', $script) }}" defer></script>
    @endforeach
@endif
