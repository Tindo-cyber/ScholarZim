@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    'list' => null,
    'strengthCheck' => false,
])

@php
    $id = $attributes->get('id', 'field-' . $name);
    $current = old($name, $value);
    $invalid = $errors->has($name);
    $isPassword = $type === 'password';
@endphp

<div class="mb-3">
    @if($label)
        <label class="form-label" for="{{ $id }}">
            {{ $label }}
            @if($required)<span class="text-danger" aria-hidden="true">*</span>@endif
        </label>
    @endif

    <div @if($isPassword) class="input-group" @endif>
        <input {{ $attributes->merge(['class' => 'form-control' . ($invalid ? ' is-invalid' : '')]) }}
               type="{{ $type }}"
               id="{{ $id }}"
               name="{{ $name }}"
               value="{{ $isPassword ? '' : $current }}"
               @if($list) list="{{ $list }}" @endif
               @if($required) required @endif
               @if($isPassword && $strengthCheck) data-password-rules @endif
               @if($hint || $strengthCheck) aria-describedby="{{ $id }}-hint" @endif>

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
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
