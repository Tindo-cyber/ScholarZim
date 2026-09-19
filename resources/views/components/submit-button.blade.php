@props([
    'label' => 'Save',
    'tone' => 'primary',
    'size' => null,
    'icon' => null,
    'busyLabel' => null,
])

{{--
    A submit button that shows it is working.

    ScholarZim is server-rendered: pressing Save is a full round trip, and on a
    free-tier host that has just woken up the round trip can be seconds long.
    Nothing on the page acknowledged the press, so the honest reading of a slow
    save was that the button had not worked, and the reasonable response was to
    press it again.

    This is not a simulated load. There is no timer and nothing is faked: the
    spinner appears on the form's own submit event, which fires only once the
    browser has accepted the submission, and it stays until the next document
    replaces this one. A form that fails its own constraint validation never
    fires submit, so an invalid form does not spin.

    It also stops the second press. The button is not disabled - disabling a
    submit button inside its own submit handler can drop the button's name and
    value from the request - so a class blocks the pointer and a flag on the
    form ignores any further submit. See resources/js/submit-state.js.
--}}
<button type="submit"
        {{ $attributes->merge(['class' => 'btn btn-' . $tone . ($size ? ' btn-' . $size : '') . ' d-inline-flex align-items-center justify-content-center gap-2']) }}
        data-sz-submit
        @if($busyLabel) data-sz-busy-label="{{ $busyLabel }}" @endif>
    <span class="spinner-border spinner-border-sm d-none" data-sz-submit-spinner aria-hidden="true"></span>
    @if($icon)
        <x-icon :name="$icon" :size="16" data-sz-submit-icon />
    @endif
    <span data-sz-submit-label>{{ $slot->isEmpty() ? $label : $slot }}</span>
</button>
