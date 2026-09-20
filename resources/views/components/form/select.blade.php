@props([
    'name',
    'label' => null,
    'options' => [],
    'value' => null,
    'placeholder' => 'Any',
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

    // is-invalid wins over is-valid: a field cannot be both, and the error
    // bag is the only one of the two the server has an opinion about.
    $stateClass = $invalid ? ' is-invalid' : ($valid ? ' is-valid' : '');

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

<div @class(['mb-3', 'opacity-50' => $disabled])>
    @if($label)
        <label class="form-label" for="{{ $id }}">
            {{ $label }}
            @if($required)<span class="text-danger" aria-hidden="true">*</span>@endif
        </label>
    @endif

    <select {{ $attributes->except('id')->merge(['class' => 'form-select' . $stateClass]) }}
            id="{{ $id }}"
            name="{{ $name }}"
            @if($required) required @endif
            @disabled($disabled)
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
