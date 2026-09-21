@props(['title', 'subtitle' => null, 'eyebrow' => null])

{{--
    The one page title on every authenticated screen. The topbar deliberately
    carries none, so this is the page's <h1> and there is nothing to duplicate.

    Four slots, all optional but the title:

      eyebrow   where this page sits - a section name, a parent record
      title     what the page is, in as few words as will do
      subtitle  one sentence on what the reader can do here
      actions   the page's own controls, primary action last-but-rightmost

    align-items-start rather than -end: a subtitle that wraps to two lines used
    to drag the action buttons down with it, so the same button sat at a
    different height on two pages that were otherwise identical.
--}}
<div class="sz-page-header d-flex flex-wrap gap-3 align-items-start justify-content-between">
    <div class="min-w-0">
        @if($eyebrow)
            <div class="sz-eyebrow">{{ $eyebrow }}</div>
        @endif

        <h1 class="h3 fw-bold mb-1">{{ $title }}</h1>

        @if($subtitle)
            <p class="text-secondary mb-0 sz-page-header-subtitle">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="d-flex flex-wrap align-items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
