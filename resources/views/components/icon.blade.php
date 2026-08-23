@props(['name' => 'circle', 'size' => 18])

@php
    // Inline stroke icons keep the shell dependency-free: no icon font to load,
    // and they inherit currentColor so tone classes work on them.
    $paths = [
        'grid' => ['M4 4h6v6H4z', 'M14 4h6v6h-6z', 'M4 14h6v6H4z', 'M14 14h6v6h-6z'],
        'search' => ['M10 4a6 6 0 1 0 0 12 6 6 0 0 0 0-12z', 'M20 20l-4-4'],
        'bell' => ['M6 9a6 6 0 1 1 12 0c0 4 1 5 1 5H5s1-1 1-5z', 'M10 19a2 2 0 0 0 4 0'],
        'person' => ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M4 20a8 8 0 0 1 16 0'],
        'people' => ['M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', 'M3 20a6 6 0 0 1 12 0', 'M17 11a3 3 0 1 0 0-6', 'M17 14a6 6 0 0 1 4 6'],
        'file-text' => ['M6 3h7l5 5v13H6z', 'M13 3v5h5', 'M9 13h6', 'M9 17h6'],
        'bookmark' => ['M7 4h10v16l-5-4-5 4z'],
        'inbox' => ['M4 13h4l1 3h6l1-3h4', 'M4 13l2-8h12l2 8v6H4z'],
        'plus' => ['M12 5v14', 'M5 12h14'],
        'chart' => ['M4 20V10', 'M10 20V4', 'M16 20v-7', 'M22 20H2'],
        'shield' => ['M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z'],
        'lock' => ['M6 11h12v9H6z', 'M9 11V8a3 3 0 0 1 6 0v3'],
        'stars' => ['M12 3l2.2 5.2L20 9l-4 3.6 1 5.4-5-2.8-5 2.8 1-5.4L4 9l5.8-.8z'],
        'menu' => ['M4 6h16', 'M4 12h16', 'M4 18h16'],
        'check' => ['M5 12l5 5L20 7'],
        'check-circle' => ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M8.5 12l2.5 2.5L15.5 10'],
        'x-circle' => ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M9 9l6 6', 'M15 9l-6 6'],
        'clock-history' => ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 7v5l3 2'],
        'hourglass-split' => ['M7 3h10', 'M7 21h10', 'M7 3c0 5 5 6 5 9s-5 4-5 9', 'M17 3c0 5-5 6-5 9s5 4 5 9'],
        'paperclip' => ['M20 11l-8 8a5 5 0 0 1-7-7l9-9a3.5 3.5 0 0 1 5 5l-9 9a2 2 0 0 1-3-3l8-8'],
        'list-ol' => ['M9 6h11', 'M9 12h11', 'M9 18h11', 'M4 6h1v3', 'M4 15h2v3H4'],
        'person-exclamation' => ['M10 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M2 20a8 8 0 0 1 14-5', 'M20 9v5', 'M20 18v.5'],
        'shield-check' => ['M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z', 'M9 12l2 2 4-4'],
        'download' => ['M12 4v10', 'M8 12l4 4 4-4', 'M4 20h16'],
        'external' => ['M14 4h6v6', 'M20 4l-8 8', 'M18 14v6H4V6h6'],
        'calendar' => ['M4 6h16v14H4z', 'M4 10h16', 'M9 3v4', 'M15 3v4'],
        'pin' => ['M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z', 'M12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z'],
        'wallet' => ['M3 7h15a2 2 0 0 1 2 2v8H5a2 2 0 0 1-2-2z', 'M16 12h3'],
        'circle' => ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z'],
        'eye' => ['M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z'],
        'eye-off' => ['M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94', 'M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19', 'M14.12 14.12a3 3 0 1 1-4.24-4.24', 'M1 1l22 22'],
        'mail' => ['M4 5h16v14H4z', 'M4 6l8 7 8-7'],
        'upload' => ['M12 16V4', 'M7 9l5-5 5 5', 'M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3'],
    ];

    $shape = $paths[$name] ?? $paths['circle'];
@endphp

<svg {{ $attributes->merge(['class' => 'svg-stroke flex-shrink-0']) }}
     width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24"
     fill="none" stroke="currentColor" stroke-width="1.8"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @foreach($shape as $d)
        <path d="{{ $d }}"></path>
    @endforeach
</svg>
