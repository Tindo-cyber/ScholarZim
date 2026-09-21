@props(['user' => null, 'name' => null, 'size' => 'md'])

@php
    $label = $user?->initials() ?? strtoupper(mb_substr(trim((string) $name) ?: 'SZ', 0, 2));

    // The dimension itself lives in scholarzim.css against these modifiers, so
    // the three sizes cannot drift from what the stylesheet knows about.
    $sizeClass = match ($size) {
        'sm' => ' sz-avatar--sm',
        'lg' => ' sz-avatar--lg',
        default => '',
    };
@endphp

<span {{ $attributes->merge(['class' => 'sz-avatar' . $sizeClass . ' d-inline-flex align-items-center justify-content-center rounded-circle fw-semibold flex-shrink-0']) }}
      aria-hidden="true">{{ $label }}</span>
