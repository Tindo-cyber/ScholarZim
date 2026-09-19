@props(['name', 'label' => null, 'value' => null, 'rows' => 4, 'hint' => null, 'required' => false])

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

    // See components/form/input.blade.php: the hint and the error message are
    // both named here so aria-invalid has something to point a reader at.
    $describedBy = array_filter([
        $hint ? $id . '-hint' : null,
        $invalid ? $id . '-error' : null,
    ]);
@endphp

<div class="mb-3">
    @if($label)
        <label class="form-label" for="{{ $id }}">
            {{ $label }}
            @if($required)<span class="text-danger" aria-hidden="true">*</span>@endif
        </label>
    @endif

    <textarea {{ $attributes->except('id')->merge(['class' => 'form-control' . ($invalid ? ' is-invalid' : '')]) }}
              id="{{ $id }}"
              name="{{ $name }}"
              rows="{{ $rows }}"
              @if($required) required @endif
              @if($invalid) aria-invalid="true" @endif
              @if($describedBy) aria-describedby="{{ implode(' ', $describedBy) }}" @endif>{{ $current }}</textarea>

    @if($hint)
        <div class="form-text" id="{{ $id }}-hint">{{ $hint }}</div>
    @endif

    @error($name)
        <div class="invalid-feedback d-block" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
</div>
