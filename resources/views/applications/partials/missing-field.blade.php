{{--
    One missing profile detail, asked for in place on the apply page. The value is saved to the applicant's profile
    (ApplicationController::saveDetails -> ApplicantProfileService::update), so it is asked for once, here, and never again.

    $field is one entry of ApplicationReadiness::$details: ['field', 'label', 'kind', 'options', 'hint'].
--}}
@props(['field'])

@php
    $name = $field['field'];
    $id = 'profile-' . str_replace('_', '-', $name);
    $current = old('profile.' . $name);
    $invalid = $errors->has('profile.' . $name);
@endphp

<div class="mb-3">
    <label class="form-label" for="{{ $id }}">{{ $field['label'] }}<span class="text-danger" aria-hidden="true">*</span></label>

    @if($field['kind'] === 'select')
        <select class="form-select @if($invalid) is-invalid @endif" id="{{ $id }}" name="profile[{{ $name }}]" required>
            <option value="">Select</option>
            @foreach($field['options'] as $value => $text)
                <option value="{{ $value }}" @selected($current === (string) $value)>{{ $text }}</option>
            @endforeach
        </select>
    @elseif($field['kind'] === 'textarea')
        <textarea class="form-control @if($invalid) is-invalid @endif" id="{{ $id }}" name="profile[{{ $name }}]"
                  rows="3" maxlength="5000" required>{{ $current }}</textarea>
    @else
        <input class="form-control @if($invalid) is-invalid @endif" id="{{ $id }}" name="profile[{{ $name }}]"
               type="{{ $field['kind'] }}" value="{{ $current }}" required
               @if($field['kind'] === 'date') max="{{ now()->subDay()->toDateString() }}" @else maxlength="255" @endif>
    @endif

    @if($field['hint'])
        <div class="form-text">{{ $field['hint'] }}</div>
    @endif
    @error('profile.' . $name)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
