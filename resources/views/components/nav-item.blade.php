@props(['href' => '#', 'icon' => 'circle', 'active' => false])

{{--
    data-sz-nav-item marks this link for the fragment-aware active handling in
    resources/js/navigation.js.

    Two items in a group can share a route and differ only by fragment -
    "My profile" and "Documents" are both /applicant/profile, one of them
    #documents. The server cannot tell them apart, because a fragment is never
    sent with the request, so it renders the fragment-less item active and the
    script corrects it from location.hash once the page is up. Without the
    script both items would not light up at once, as they used to: the one
    without a fragment wins, which is the honest answer to "which page am I on".
--}}
<li class="nav-item">
    <a class="nav-link d-flex align-items-center gap-2 {{ $active ? 'active' : 'text-body' }}"
       href="{{ $href }}"
       data-sz-nav-item
       @if($active) aria-current="page" @endif>
        <x-icon :name="$icon" />
        <span class="flex-grow-1 d-flex align-items-center gap-2 min-w-0">{{ $slot }}</span>
    </a>
</li>
