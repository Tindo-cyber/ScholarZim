@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    /*
     * The states beyond "normal", all opt-in.
     *
     * `invalid` is deliberately not one of them: it is derived from the error
     * bag, because a field that has to be told it failed validation is a field
     * that can be told wrongly.
     *
     * `valid` is unused across the app today. A tick on every field somebody
     * has merely filled in marks the absence of a problem, which was already
     * the absence of a problem - so it is here for the one form that earns it
     * rather than applied by default.
     *
     * `disabled` also dims the label. Bootstrap styles only the control, which
     * left a bright label sitting over a greyed-out field.
     */
    'disabled' => false,
    'readonly' => false,
    'valid' => false,
    'list' => null,
    'strengthCheck' => false,
])

@php
    /*
     * except('id'): $attributes still holds the id that $id was just read from,
     * so merging the bag straight onto the control printed id="..." twice on the
     * same tag whenever a caller passed one. Browsers honour the first and carry
     * on, which is why it went unnoticed - but it is invalid HTML, and a
     * duplicate id is exactly the sort of thing a screen reader resolves
     * differently from the browser.
     */
    $id = $attributes->get('id', 'field-' . $name);
    $current = old($name, $value);
    $invalid = $errors->has($name);

    // is-invalid wins over is-valid: a field cannot be both, and the error
    // bag is the only one of the two the server has an opinion about.
    $stateClass = $invalid ? ' is-invalid' : ($valid ? ' is-valid' : '');
    $isPassword = $type === 'password';

    /*
     * Everything that describes this field, in reading order. The error message
     * used to be rendered but never referenced, so a screen reader announced a
     * field as invalid - once aria-invalid was added - without ever reading out
     * why. Both ids are listed here and both elements carry them below.
     */
    $describedBy = array_filter([
        ($hint || $strengthCheck) ? $id . '-hint' : null,
        $invalid ? $id . '-error' : null,
    ]);
@endphp

<div @class(['mb-3', 'opacity-50' => $disabled])>
    @if($label)
        <label class="form-label" for="{{ $id }}">
            {{ $label }}
            @if($required)<span class="text-danger" aria-hidden="true">*</span>@endif
        </label>
    @endif

    <div @if($isPassword) class="input-group" @endif>
        <input {{ $attributes->except('id')->merge(['class' => 'form-control' . $stateClass]) }}
               type="{{ $type }}"
               id="{{ $id }}"
               name="{{ $name }}"
               value="{{ $isPassword ? '' : $current }}"
               @if($list) list="{{ $list }}" @endif
               @if($required) required @endif
               @disabled($disabled)
               @readonly($readonly)
               @if($invalid) aria-invalid="true" @endif
               @if($isPassword && $strengthCheck) data-password-rules @endif
               @if($describedBy) aria-describedby="{{ implode(' ', $describedBy) }}" @endif>

        @if($isPassword)
            <button class="btn btn-outline-secondary{{ $invalid ? ' border-danger' : '' }}" type="button"
                    data-password-toggle="{{ $id }}" aria-pressed="false" aria-label="Show password">
                <x-icon name="eye" :size="16" data-icon-show />
                <x-icon name="eye-off" :size="16" class="d-none" data-icon-hide />
            </button>
        @endif
    </div>

    @if($strengthCheck)
        <ul class="sz-pw-checklist list-unstyled small mb-0 mt-2" id="{{ $id }}-hint" data-password-checklist="{{ $id }}">
            <li class="text-secondary" data-rule="length">
                <x-icon name="circle" :size="14" data-icon-pending /><x-icon name="check-circle" :size="14" class="d-none" data-icon-met />
                At least 8 characters
            </li>
            <li class="text-secondary" data-rule="letter">
                <x-icon name="circle" :size="14" data-icon-pending /><x-icon name="check-circle" :size="14" class="d-none" data-icon-met />
                At least one letter
            </li>
            <li class="text-secondary" data-rule="number">
                <x-icon name="circle" :size="14" data-icon-pending /><x-icon name="check-circle" :size="14" class="d-none" data-icon-met />
                At least one number
            </li>
        </ul>
    @elseif($hint)
        <div class="form-text" id="{{ $id }}-hint">{{ $hint }}</div>
    @endif

    @error($name)
        <div class="invalid-feedback d-block" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
</div>
