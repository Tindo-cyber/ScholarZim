@props([
    'label' => 'Save',
    'tone' => 'primary',
    'size' => null,
    'icon' => null,
    'busyLabel' => null,
    /**
     * A control the page is offering but cannot accept yet - a review form on a
     * decided application, a step whose prerequisite is unmet. Not the busy
     * state: that one the button enters by itself.
     */
    'disabled' => false,
    'disabledReason' => null,
])

{{--
    A submit button with three states.

      NORMAL    [ Submit ]
      BUSY      [ ◌ ] Saving...        entered on the form's own submit event
      DISABLED  [ Submit ]  dimmed     set by the caller, never by the script

    ScholarZim is server-rendered: pressing Save is a full round trip, and on a
    free-tier host that has just woken up the round trip can be seconds long.
    Nothing on the page acknowledged the press, so the honest reading of a slow
    save was that the button had not worked, and the reasonable response was to
    press it again.

    The busy state is not a simulation. There is no timer and nothing is faked:
    the spinner appears on the form's own submit event, which fires only once
    the browser has accepted the submission, and it stays until the next
    document replaces this one. A form that fails its own constraint validation
    never fires submit, so an invalid form does not spin.

    Progressive enhancement, in both directions. With no JavaScript the button
    is an ordinary submit and the form posts exactly as it always did - nothing
    here is required for the form to work. With JavaScript, it also stops the
    second press: the button is not disabled on submit, because disabling a
    submit button inside its own submit handler can drop its name and value
    from the request - which the provider's Accept/Reject pair depends on - so a
    class blocks the pointer and a flag on the form ignores any further submit.
    See resources/js/submit-state.js.
--}}
<button type="submit"
        {{ $attributes->merge(['class' => 'btn btn-' . $tone . ($size ? ' btn-' . $size : '') . ' d-inline-flex align-items-center justify-content-center gap-2']) }}
        data-sz-submit
        @if($disabled) disabled aria-disabled="true" @endif
        @if($disabled && $disabledReason) title="{{ $disabledReason }}" @endif
        @if($busyLabel) data-sz-busy-label="{{ $busyLabel }}" @endif>
    <span class="spinner-border spinner-border-sm d-none" data-sz-submit-spinner aria-hidden="true"></span>
    @if($icon)
        <x-icon :name="$icon" :size="16" data-sz-submit-icon />
    @endif
    <span data-sz-submit-label>{{ $slot->isEmpty() ? $label : $slot }}</span>
</button>
