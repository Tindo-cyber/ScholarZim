@props([
    'name',
    // Plain text is the common case. A label that needs markup - a bolder first
    // line, a link to terms - goes in the slot instead and this stays null.
    'label' => null,
    'value' => '1',
    'checked' => false,
    'hint' => null,
    'switch' => false,
    'required' => false,
    'disabled' => false,
    /*
     * Spacing on the wrapper, not on the input.
     *
     * $attributes go to the <input>, matching form/input.blade.php, because
     * that is where callers need them: an id, a data-* hook, a form= binding.
     * That leaves the wrapper with nothing to receive a margin through, and a
     * checkbox turns up in too many places for one default to be right - a row
     * of switches wants mb-3, the last item in a card wants mb-0, and the one
     * beside "Forgot your password?" wants nothing at all.
     */
    'wrapperClass' => 'mb-3',
])

@php
    /*
     * A name like documents[transcript] cannot be an id as it stands, hence the
     * substitution.
     *
     * except('id') below: $attributes still holds the id that $id is read from,
     * so merging the bag straight onto the control printed id="..." twice on the
     * same tag whenever a caller passed one. Browsers honour the first and carry
     * on, which is why it went unnoticed - but it is invalid HTML, and a
     * duplicate id is exactly the sort of thing a screen reader resolves
     * differently from the browser.
     */
    $id = $attributes->get('id', 'field-' . trim(str_replace(['[', ']', '.'], '-', $name), '-'));
    $invalid = $errors->has($name);

    /*
     * old() with a default is wrong for a checkbox and this is the one place it
     * matters. An unticked box is simply absent from the request, so after a
     * failed submit old($name, $checked) falls back to $checked and silently
     * re-ticks a box the user had just cleared. Asking the session whether it
     * holds any old input at all separates "first render, use the stored value"
     * from "a submit came back, use what they actually sent".
     */
    $isChecked = session()->hasOldInput()
        ? (bool) old($name)
        : (bool) $checked;

    $describedBy = array_filter([
        $hint ? $id . '-hint' : null,
        $invalid ? $id . '-error' : null,
    ]);
@endphp

<div @class(['form-check', 'form-switch' => $switch, 'opacity-50' => $disabled, $wrapperClass => $wrapperClass])>
    <input {{ $attributes->except('id')->merge(['class' => 'form-check-input' . ($invalid ? ' is-invalid' : '')]) }}
           type="checkbox"
           id="{{ $id }}"
           name="{{ $name }}"
           value="{{ $value }}"
           @if($switch) role="switch" @endif
           @if($required) required @endif
           @disabled($disabled)
           @if($invalid) aria-invalid="true" @endif
           @if($describedBy) aria-describedby="{{ implode(' ', $describedBy) }}" @endif
           @checked($isChecked)>

    <label class="form-check-label" for="{{ $id }}">
        {{ $label ?? $slot }}
        @if($required)<span class="text-danger" aria-hidden="true">*</span>@endif

        @if($hint)
            <span class="d-block small text-secondary" id="{{ $id }}-hint">{{ $hint }}</span>
        @endif
    </label>

    @error($name)
        <div class="invalid-feedback d-block" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
</div>
