@props([
    'title' => 'Nothing here yet',
    'message' => null,
    'icon' => 'inbox',
    'actionLabel' => null,
    'actionHref' => null,
    /**
     * The heading level for the title. h2 suits an empty state that replaces a
     * page's main content, which is nearly all of them; a card sitting inside
     * an h2 section under an h3 card header passes h4, so the document outline
     * does not jump back up a level in the middle of a page.
     */
    'level' => 'h2',
])

<div {{ $attributes->merge(['class' => 'text-center py-5 px-3']) }}>
    <span class="sz-empty-icon bg-body-secondary text-secondary rounded-circle d-inline-flex align-items-center justify-content-center mb-3">
        <x-icon :name="$icon" :size="26" />
    </span>

    <{{ $level }} class="h6 fw-semibold mb-1">{{ $title }}</{{ $level }}>

    @if($message)
        <p class="text-secondary mb-3 mx-auto sz-empty-message">{{ $message }}</p>
    @endif

    @if($actionLabel && $actionHref)
        <a class="btn btn-primary btn-sm" href="{{ $actionHref }}">{{ $actionLabel }}</a>
    @endif

    {{ $slot }}
</div>
