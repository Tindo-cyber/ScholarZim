@props([
    'title' => 'Nothing here yet',
    'message' => null,
    'icon' => 'inbox',
    'actionLabel' => null,
    'actionHref' => null,
])

<div {{ $attributes->merge(['class' => 'text-center py-5 px-3']) }}>
    <span class="sz-empty-icon bg-body-secondary text-secondary rounded-circle d-inline-flex align-items-center justify-content-center mb-3">
        <x-icon :name="$icon" :size="26" />
    </span>

    <h2 class="h6 fw-semibold mb-1">{{ $title }}</h2>

    @if($message)
        <p class="text-secondary mb-3 mx-auto" style="max-width: 28rem;">{{ $message }}</p>
    @endif

    @if($actionLabel && $actionHref)
        <a class="btn btn-primary btn-sm" href="{{ $actionHref }}">{{ $actionLabel }}</a>
    @endif

    {{ $slot }}
</div>
