@props(['user' => null, 'name' => null, 'size' => 'md'])

@php
    $label = $user?->initials() ?? strtoupper(mb_substr(trim((string) $name) ?: 'SZ', 0, 2));

    $dimension = match ($size) {
        'sm' => '2rem',
        'lg' => '3.25rem',
        default => '2.5rem',
    };
@endphp

<span {{ $attributes->merge(['class' => 'sz-avatar d-inline-flex align-items-center justify-content-center rounded-circle fw-semibold flex-shrink-0']) }}
      style="width: {{ $dimension }}; height: {{ $dimension }};"
      aria-hidden="true">{{ $label }}</span>
