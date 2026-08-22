@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    'list' => null,
])

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

    <input {{ $attributes->merge(['class' => 'form-control' . ($invalid ? ' is-invalid' : '')]) }}
           type="{{ $type }}"
           id="{{ $id }}"
           name="{{ $name }}"
           value="{{ $type === 'password' ? '' : $current }}"
           @if($list) list="{{ $list }}" @endif
           @if($required) required @endif
           @if($hint) aria-describedby="{{ $id }}-hint" @endif>

    @if($hint)
        <div class="form-text" id="{{ $id }}-hint">{{ $hint }}</div>
    @endif

    @error($name)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
