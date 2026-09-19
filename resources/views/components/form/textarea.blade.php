@props(['name', 'label' => null, 'value' => null, 'rows' => 4, 'hint' => null, 'required' => false])

@php
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

    <textarea {{ $attributes->merge(['class' => 'form-control' . ($invalid ? ' is-invalid' : '')]) }}
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
