@props([
    'name',
    'label' => null,
    'options' => [],
    'value' => null,
    'placeholder' => 'Any',
    'hint' => null,
    'required' => false,
    'grouped' => false,
])

@php
    $id = $attributes->get('id', 'field-' . $name);
    $current = old($name, $value);
    $invalid = $errors->has($name);

    // Accepts either a flat list, a value => label map, or (when $grouped) a
    // group => options map for optgroups.
    $normalize = static function (array $items): array {
        $isList = array_is_list($items);

        return $isList ? array_combine($items, $items) : $items;
    };
@endphp

<div class="mb-3">
    @if($label)
        <label class="form-label" for="{{ $id }}">
            {{ $label }}
            @if($required)<span class="text-danger" aria-hidden="true">*</span>@endif
        </label>
    @endif

    <select {{ $attributes->merge(['class' => 'form-select' . ($invalid ? ' is-invalid' : '')]) }}
            id="{{ $id }}"
            name="{{ $name }}"
            @if($required) required @endif>

        @if($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif

        @if($grouped)
            @foreach($options as $group => $groupOptions)
                <optgroup label="{{ $group }}">
                    @foreach($normalize($groupOptions) as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected((string) $current === (string) $optionValue)>{{ $optionLabel }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        @else
            @foreach($normalize($options) as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) $current === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        @endif
    </select>

    @if($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif

    @error($name)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
