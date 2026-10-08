@extends('layouts.app')

@section('title', $programme->exists ? 'Edit programme' : 'Add a programme')

@section('content')

    <x-page-header :title="$programme->exists ? 'Edit programme' : 'Add a programme'" eyebrow="Governance" />

    @include('admin.catalogue.nav', ['active' => 'programmes'])

    <form method="POST"
          action="{{ $programme->exists ? route('admin.catalogue.programmes.update', $programme->id) : route('admin.catalogue.programmes.store') }}"
          novalidate class="card"><div class="card-body">
        @csrf

        <div class="row">
            <div class="col-md-8 mb-3">
                <label class="form-label" for="name">Name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" maxlength="200"
                       value="{{ old('name', $programme->name) }}">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label" for="education_level">Level</label>
                <select class="form-select @error('education_level') is-invalid @enderror" id="education_level" name="education_level">
                    @foreach($levels as $level)
                        <option value="{{ $level }}" @selected(old('education_level', $programme->education_level) === $level)>{{ \App\Support\EducationLevel::label($level) }}</option>
                    @endforeach
                </select>
                @error('education_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="field_id">Field</label>
            <select class="form-select @error('field_id') is-invalid @enderror" id="field_id" name="field_id">
                @foreach($fields->groupBy(fn ($f) => $f->parent->name) as $broad => $narrow)
                    <optgroup label="{{ $broad }}">
                        @foreach($narrow as $field)
                            <option value="{{ $field->id }}" @selected((int) old('field_id', $programme->field_id) === $field->id)>{{ $field->code }} {{ $field->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            @error('field_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="synonyms">Other names (one per line)</label>
            <textarea class="form-control" id="synonyms" name="synonyms" rows="4" aria-describedby="synonyms-help">{{ old('synonyms', $synonymText) }}</textarea>
            <div class="form-text" id="synonyms-help">Abbreviations and common short forms: BIS, Info Systems, Comp Sci. Students and providers can find the programme by any of them.</div>
        </div>

        <fieldset class="mb-3">
            <legend class="form-label fs-6">Offered at</legend>
            <div class="row">
                @foreach($institutions->groupBy('type') as $type => $group)
                    <div class="col-md-4 mb-2">
                        <div class="small fw-semibold">{{ \App\Models\Institution::TYPE_LABELS[$type] ?? $type }}</div>
                        @foreach($group as $institution)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="institutions[]" value="{{ $institution->id }}"
                                       id="inst-{{ $institution->id }}" @checked(in_array($institution->id, (array) old('institutions', $chosenInstitutions)))>
                                <label class="form-check-label small" for="inst-{{ $institution->id }}">{{ $institution->name }}</label>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </fieldset>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $programme->is_active ?? true))>
            <label class="form-check-label" for="is_active">Offered in the catalogue (untick to hide it from pickers)</label>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-outline-secondary" href="{{ route('admin.catalogue.programmes') }}">Cancel</a>
        </div>
    </div></form>

@endsection
