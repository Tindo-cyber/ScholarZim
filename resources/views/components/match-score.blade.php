@props(['score' => 0, 'label' => null, 'size' => 'md', 'showLabel' => true])

@php
    $score = max(0, min(100, (int) $score));

    $tone = match (true) {
        $score >= 75 => 'success',
        $score >= 45 => 'warning',
        default => 'secondary',
    };

    $dimension = $size === 'lg' ? 96 : 64;
    $stroke = $size === 'lg' ? 8 : 6;
    $radius = ($dimension - $stroke) / 2;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - $score / 100);
@endphp

<div {{ $attributes->merge(['class' => 'd-inline-flex flex-column align-items-center gap-1']) }}>
    <div class="position-relative" style="width: {{ $dimension }}px; height: {{ $dimension }}px;">
        <svg width="{{ $dimension }}" height="{{ $dimension }}" viewBox="0 0 {{ $dimension }} {{ $dimension }}"
             role="img" aria-label="ScholarFit score {{ $score }} out of 100">
            <circle cx="{{ $dimension / 2 }}" cy="{{ $dimension / 2 }}" r="{{ $radius }}"
                    fill="none" stroke="currentColor" stroke-width="{{ $stroke }}" class="text-body-secondary opacity-25"/>
            <circle cx="{{ $dimension / 2 }}" cy="{{ $dimension / 2 }}" r="{{ $radius }}"
                    fill="none" stroke-width="{{ $stroke }}" stroke-linecap="round"
                    class="text-{{ $tone }}" stroke="currentColor"
                    stroke-dasharray="{{ $circumference }}"
                    stroke-dashoffset="{{ $offset }}"
                    transform="rotate(-90 {{ $dimension / 2 }} {{ $dimension / 2 }})"/>
        </svg>

        <span class="position-absolute top-50 start-50 translate-middle fw-bold {{ $size === 'lg' ? 'fs-4' : 'small' }}">
            {{ $score }}%
        </span>
    </div>

    @if($showLabel)
        <span class="small text-{{ $tone }} fw-semibold">{{ $label ?? 'ScholarFit' }}</span>
    @endif
</div>
