@props(['title', 'subtitle' => null, 'eyebrow' => null])

<div class="d-flex flex-wrap gap-3 align-items-end justify-content-between mb-4">
    <div class="min-w-0">
        @if($eyebrow)
            <div class="text-uppercase small fw-semibold text-primary mb-1">{{ $eyebrow }}</div>
        @endif
        <h1 class="h3 fw-bold mb-1">{{ $title }}</h1>
        @if($subtitle)
            <p class="text-secondary mb-0">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="d-flex flex-wrap gap-2">{{ $actions }}</div>
    @endisset
</div>
