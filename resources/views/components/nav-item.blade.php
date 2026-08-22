@props(['href' => '#', 'icon' => 'circle', 'active' => false])

<li class="nav-item">
    <a class="nav-link d-flex align-items-center gap-2 {{ $active ? 'active' : 'text-body' }}"
       href="{{ $href }}"
       @if($active) aria-current="page" @endif>
        <x-icon :name="$icon" />
        <span class="flex-grow-1 d-flex align-items-center gap-2">{{ $slot }}</span>
    </a>
</li>
