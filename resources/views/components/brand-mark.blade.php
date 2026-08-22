@props(['tone' => 'primary'])

@php
    $fill = $tone === 'light' ? '#ffffff' : 'var(--primary-color)';
@endphp

{{-- Graduation cap over an upward chevron: study plus progression. --}}
<svg {{ $attributes->merge(['class' => 'flex-shrink-0']) }} width="28" height="28" viewBox="0 0 32 32"
     fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <path d="M16 5 30 12l-14 7L2 12z" fill="{{ $fill }}"/>
    <path d="M8 15.5V22c0 2.2 3.6 4 8 4s8-1.8 8-4v-6.5" stroke="{{ $fill }}" stroke-width="2.4"
          stroke-linecap="round" stroke-linejoin="round" opacity=".55"/>
</svg>
