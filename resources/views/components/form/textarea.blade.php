@props(['name', 'label' => null, 'value' => null, 'rows' => 4, 'hint' => null, 'required' => false])

@php
    $id = $attributes->get('id', 'field-' . $name);
    $current = old($name, $value);
    $invalid = $errors->has($name);
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
              @if($required) required @endif>{{ $current }}</textarea>

    @if($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif

    @error($name)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
