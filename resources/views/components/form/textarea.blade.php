@props([
    'name',
    'label' => null,
    'value' => null,
    'rows' => 4,
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

    // See components/form/input.blade.php: the hint and the error message are
    // both named here so aria-invalid has something to point a reader at.
    $describedBy = array_filter([
        $hint ? $id . '-hint' : null,
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

    <textarea {{ $attributes->except('id')->merge(['class' => 'form-control' . $stateClass]) }}
              id="{{ $id }}"
              name="{{ $name }}"
              rows="{{ $rows }}"
              @if($required) required @endif
              @disabled($disabled)
              @readonly($readonly)
              @if($invalid) aria-invalid="true" @endif
              @if($describedBy) aria-describedby="{{ implode(' ', $describedBy) }}" @endif>{{ $current }}</textarea>

    @if($hint)
        <div class="form-text" id="{{ $id }}-hint">{{ $hint }}</div>
    @endif

    @error($name)
        <div class="invalid-feedback d-block" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
</div>
