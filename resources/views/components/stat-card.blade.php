@props([
    'label',
    'value',
    'icon' => 'chart',
    'tone' => 'primary',
    'hint' => null,
    'href' => null,
    'progress' => null,
])

@php
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if($href) href="{{ $href }}" @endif
   {{ $attributes->merge(['class' => 'card sz-stat-card h-100 text-decoration-none' . ($href ? ' sz-stat-card--link' : '')]) }}>
    <div class="card-body d-flex gap-3 align-items-start">
        <span class="sz-stat-icon bg-{{ $tone }}-subtle text-{{ $tone }} rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0">
            <x-icon :name="$icon" :size="20" />
        </span>

        <div class="min-w-0 flex-grow-1">
            <div class="text-secondary small text-uppercase fw-semibold">{{ $label }}</div>
            <div class="fs-3 fw-bold lh-1 my-1">{{ $value }}</div>

            @if(!is_null($progress))
                <div class="progress mt-2" style="height: .375rem;" role="progressbar"
                     aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"
                     aria-label="{{ $label }} progress">
                    <div class="progress-bar bg-{{ $tone }}" style="width: {{ max(0, min(100, $progress)) }}%"></div>
                </div>
            @endif

            @if($hint)
                <div class="small text-secondary mt-1">{{ $hint }}</div>
            @endif
        </div>
    </div>
</{{ $tag }}>
