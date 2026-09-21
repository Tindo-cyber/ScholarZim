@props([
    'id',
    'action',
    'title',
    'confirmLabel',
    // Null renders the dialog alone. Needed where the control that opens it
    // cannot be a sibling of the dialog - the provider dashboard opens both of
    // its dialogs from items inside a dropdown menu, and a modal nested in an
    // open dropdown is clipped by it. Such a caller writes its own trigger with
    // data-bs-toggle="modal" data-bs-target="#<id>".
    'triggerLabel' => null,
    'message' => null,
    'tone' => 'danger',
    'triggerClass' => 'btn btn-sm btn-outline-danger',
    'triggerIcon' => null,
    'method' => 'POST',
    'cancelLabel' => 'Cancel',
])

{{--
    One way to ask "are you sure?".

    Before this there were two, and neither was the same twice. Four hand-rolled
    modals - decline a listing, reject a provider, extend a deadline, withdraw a
    listing - repeated the same forty lines of modal-dialog/modal-content/
    modal-header/btn-close scaffolding around a different field each time, and
    deleting a user went through the browser's own confirm(), which cannot be
    styled, cannot explain what is about to happen, and cannot collect the
    reason the action is about to record.

    The trigger and the dialog are emitted together on purpose: an id that has
    to be typed twice is an id that eventually gets typed once.

    Anything the action needs beyond confirmation - a reason, a new date - goes
    in the slot and lands in the same form as the confirm button.

    Accessibility is Bootstrap's: the modal traps focus, Escape closes it, and
    focus returns to the trigger. aria-labelledby points at the title so the
    dialog announces what it is about rather than just "dialog".
--}}
@if($triggerLabel)
    <button {{ $attributes->merge(['class' => $triggerClass]) }} type="button"
            data-bs-toggle="modal" data-bs-target="#{{ $id }}">
        @if($triggerIcon)
            <x-icon :name="$triggerIcon" :size="14" />
        @endif
        {{ $triggerLabel }}
    </button>
@endif

<div class="modal fade text-start" id="{{ $id }}" tabindex="-1"
     aria-labelledby="{{ $id }}-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ $action }}" class="modal-content">
            @csrf
            @if($method !== 'POST')
                @method($method)
            @endif

            <div class="modal-header">
                <h3 class="modal-title h6" id="{{ $id }}-title">{{ $title }}</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                @if($message)
                    <p class="text-secondary{{ $slot->isEmpty() ? ' mb-0' : '' }}">{{ $message }}</p>
                @endif

                {{ $slot }}
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ $cancelLabel }}</button>
                <x-submit-button :label="$confirmLabel" :tone="$tone" />
            </div>
        </form>
    </div>
</div>
