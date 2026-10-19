@props([
    'name',
    'id',
    'label',
    'max',
    'groups',
    'hint' => null,
    'noun' => 'choices',
    // The validation key when it is not the posted name (a field posted as profile[x][] is invalid as profile.x).
    'errorKey' => null,
])

{{--
    A pick-up-to-N list: a checkbox for every option, all visible at once, so choosing several is one click each
    instead of a Ctrl-click on a plain multi-select that nobody discovers.

    $groups: [['label' => 'Diploma', 'options' => [['id' => 5, 'label' => '...', 'search' => 'lower case', 'selected' => bool], ...]], ...]

    Works with JavaScript off: it is an ordinary group of checkboxes posting `name[]`. resources/js/limited-choice.js
    adds the search box, the running count, and stops a further box being ticked once N are. Every one of those
    is a convenience; the server refuses more than N and is the rule (ApplicantProgrammes::save).
--}}
<fieldset class="mb-3" data-limited-choice data-max="{{ $max }}" data-noun="{{ $noun }}" id="{{ $id }}"
          aria-describedby="{{ $id }}-help">
    <legend class="form-label">{{ $label }}</legend>
    <div class="form-text mb-1" id="{{ $id }}-help">
        Tick up to {{ $max }}. <span data-limited-count aria-live="polite"></span>
        @if($hint) {{ $hint }} @endif
    </div>
    <div class="border rounded p-2 overflow-auto" style="max-height: 18rem" data-limited-list>
        @foreach($groups as $group)
            @continue(empty($group['options']))
            <div data-limited-group>
                <div class="small fw-semibold text-secondary mt-1">{{ $group['label'] }}</div>
                @foreach($group['options'] as $option)
                    <div class="form-check" data-limited-option data-search="{{ $option['search'] ?? mb_strtolower($option['label']) }}">
                        <input class="form-check-input" type="checkbox" name="{{ $name }}[]" value="{{ $option['id'] }}"
                               id="{{ $id }}-{{ $option['id'] }}" @checked($option['selected'] ?? false)>
                        <label class="form-check-label" for="{{ $id }}-{{ $option['id'] }}">{{ $option['label'] }}</label>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
    @error($errorKey ?? $name)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</fieldset>
