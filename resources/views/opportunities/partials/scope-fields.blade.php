@props(['opportunity' => null])

@php
    /**
     * "Open to": which programmes, fields and institutions this scholarship is for.
     *
     * Plain multiple <select>s, so the form works with JavaScript off. resources/js/catalogue-filter.js
     * only adds a search box above each list and hides programmes of another level than the one
     * chosen above; the server checks the same things (ListingScopes::problems).
     *
     * Leave everything empty and the award is open to any programme.
     */
    $scopes = $opportunity?->scopes ?? collect();
    $chosen = [
        'programmes' => array_map('intval', (array) old('scope_programmes', $scopes->pluck('programme_id')->filter()->all())),
        'fields' => array_map('intval', (array) old('scope_fields', $scopes->pluck('field_id')->filter()->all())),
        'institutions' => array_map('intval', (array) old('scope_institutions', $scopes->pluck('institution_id')->filter()->all())),
    ];
    $typeLabels = \App\Models\Institution::TYPE_LABELS;
@endphp

<div class="card mb-4" data-level-needs="programmes" id="scope-card">
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">Open to (optional)</h2>
    </div>
    <div class="card-body">
        <p class="small text-secondary">
            Leave this empty and any student at the level above can be matched. To narrow it, choose programmes,
            a whole field (such as Engineering), or institutions. A student must fit the programmes <em>or</em> fields
            you choose, <em>and</em> be at one of the institutions you choose, if you choose any.
        </p>

        <div class="mb-3" data-catalogue-filter>
            <label class="form-label" for="scope-programmes">Programmes</label>
            <select class="form-select @error('scope_programmes') is-invalid @enderror" id="scope-programmes"
                    name="scope_programmes[]" multiple size="8" aria-describedby="scope-programmes-help" data-filter-level="field-education_level">
                @foreach($catalogue['programmes']->groupBy('education_level') as $level => $programmes)
                    <optgroup label="{{ \App\Support\EducationLevel::label($level) }}" data-level="{{ $level }}">
                        @foreach($programmes as $programme)
                            <option value="{{ $programme->id }}" data-level="{{ $level }}"
                                    data-search="{{ strtolower($programme->name . ' ' . $programme->synonyms->pluck('synonym')->implode(' ')) }}"
                                    @selected(in_array($programme->id, $chosen['programmes'], true))>{{ $programme->name }}@if($programme->isPending()) (waiting for approval)@endif</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <div class="form-text" id="scope-programmes-help">Hold Ctrl (or Cmd) to choose several. You can search by name or abbreviation, such as BIS.</div>
            @error('scope_programmes')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            @foreach($errors->get('scope_programmes.*') as $messages)<div class="invalid-feedback d-block">{{ $messages[0] }}</div>@endforeach
        </div>

        <div class="mb-3" data-catalogue-filter>
            <label class="form-label" for="scope-fields">Fields</label>
            <select class="form-select @error('scope_fields') is-invalid @enderror" id="scope-fields"
                    name="scope_fields[]" multiple size="6" aria-describedby="scope-fields-help">
                @foreach($catalogue['fields'] as $broad)
                    <optgroup label="{{ $broad->label() }}">
                        <option value="{{ $broad->id }}" data-search="{{ strtolower($broad->label() . ' ' . $broad->name) }}"
                                @selected(in_array($broad->id, $chosen['fields'], true))>All of {{ $broad->label() }}</option>
                        @foreach($broad->children as $narrow)
                            <option value="{{ $narrow->id }}" data-search="{{ strtolower($narrow->label() . ' ' . $narrow->name . ' ' . $broad->label()) }}"
                                    @selected(in_array($narrow->id, $chosen['fields'], true))>{{ $narrow->label() }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <div class="form-text" id="scope-fields-help">
                Choose a field to open the award to every programme in it, so you do not have to tick them one by one.
                Leave empty for any field.
            </div>
            @error('scope_fields')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            @foreach($errors->get('scope_fields.*') as $messages)<div class="invalid-feedback d-block">{{ $messages[0] }}</div>@endforeach
        </div>

        <div class="mb-3" data-catalogue-filter>
            <label class="form-label" for="scope-institutions">Institutions</label>
            <select class="form-select @error('scope_institutions') is-invalid @enderror" id="scope-institutions"
                    name="scope_institutions[]" multiple size="6">
                @foreach($catalogue['institutions']->groupBy('type') as $type => $institutions)
                    <optgroup label="{{ $typeLabels[$type] ?? $type }}">
                        @foreach($institutions as $institution)
                            <option value="{{ $institution->id }}" data-search="{{ strtolower($institution->name . ' ' . $institution->code) }}"
                                    @selected(in_array($institution->id, $chosen['institutions'], true))>{{ $institution->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <div class="form-text">Only for students at these institutions. Leave empty for any institution.</div>
            @error('scope_institutions')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <details @if(old('programme_suggestion')) open @endif>
            <summary class="small">My programme isn't listed</summary>
            <div class="row g-2 mt-1">
                <div class="col-md-7">
                    <label class="form-label small" for="programme_suggestion">Programme name</label>
                    <input class="form-control @error('programme_suggestion') is-invalid @enderror" id="programme_suggestion"
                           name="programme_suggestion" maxlength="200" value="{{ old('programme_suggestion') }}">
                    @error('programme_suggestion')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-5">
                    <label class="form-label small" for="programme_suggestion_field">Its field</label>
                    <select class="form-select @error('programme_suggestion_field') is-invalid @enderror" id="programme_suggestion_field" name="programme_suggestion_field">
                        <option value="">Choose a field</option>
                        @foreach($catalogue['fields'] as $broad)
                            <optgroup label="{{ $broad->label() }}">
                                @foreach($broad->children as $narrow)
                                    <option value="{{ $narrow->id }}" @selected((int) old('programme_suggestion_field') === $narrow->id)>{{ $narrow->label() }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('programme_suggestion_field')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <p class="form-text">
                It is added at the level above and used for this listing straight away. An administrator then checks it: it is
                either added to the catalogue or matched to the programme it really is.
            </p>
        </details>
    </div>
</div>
