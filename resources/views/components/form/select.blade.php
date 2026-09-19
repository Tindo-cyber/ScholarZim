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

    <select {{ $attributes->except('id')->merge(['class' => 'form-select' . ($invalid ? ' is-invalid' : '')]) }}
            id="{{ $id }}"
            name="{{ $name }}"
            @if($required) required @endif
            @if($invalid) aria-invalid="true" @endif
            @if($describedBy) aria-describedby="{{ implode(' ', $describedBy) }}" @endif>

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
        <div class="form-text" id="{{ $id }}-hint">{{ $hint }}</div>
    @endif

    @error($name)
        <div class="invalid-feedback d-block" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
</div>
