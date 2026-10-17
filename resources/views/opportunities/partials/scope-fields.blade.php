@props(['opportunity' => null])

@php
    /**
     * "Open to": which programmes, fields and institutions this scholarship is for.
     *
     * At Certificate level and above this is a decision the provider must make, because ScholarFit uses it to
     * tell each student whether they qualify (ListingScopes::decisionProblems): either the programmes or
     * fields it is for, or "All programmes at this level" on purpose; and, for study in Zimbabwe, either the
     * institutions it pays for or "Any institution". For study abroad the Zimbabwean programme and institution
     * lists do not apply - the award is open to fields, and its host universities are written in words.
     *
     * Plain radios and multiple <select>s, so the form works with JavaScript off. resources/js/catalogue-filter.js
     * adds a search box over each list and resources/js/open-to.js hides what the answers make irrelevant; the
     * server checks the same things and drops anything an answer overrides.
     */
    $scopes = $opportunity?->scopes ?? collect();
    $chosen = [
        'programmes' => array_map('intval', (array) old('scope_programmes', $scopes->pluck('programme_id')->filter()->all())),
        'fields' => array_map('intval', (array) old('scope_fields', $scopes->pluck('field_id')->filter()->all())),
        'institutions' => array_map('intval', (array) old('scope_institutions', $scopes->pluck('institution_id')->filter()->all())),
    ];
    $openTo = old('open_to', match (true) {
        $opportunity?->open_to_all_programmes === true => \App\Services\Catalogue\ListingScopes::OPEN_ALL,
        $opportunity?->open_to_all_programmes === false, $chosen['programmes'] !== [] || $chosen['fields'] !== [] => \App\Services\Catalogue\ListingScopes::OPEN_CHOSEN,
        default => null,
    });
    $institutionsOpenTo = old('institutions_open_to', match (true) {
        $opportunity?->any_institution === true => \App\Services\Catalogue\ListingScopes::INSTITUTIONS_ANY,
        $opportunity?->any_institution === false, $chosen['institutions'] !== [] => \App\Services\Catalogue\ListingScopes::INSTITUTIONS_CHOSEN,
        default => null,
    });
    $abroad = \App\Services\Catalogue\ListingScopes::isAbroad(old('country', $opportunity?->country));
    // A list that has to be cleared before saving stays in view, so its error can be read and acted on.
    $hideProgrammes = $abroad && ! $errors->has('scope_programmes') && ! $errors->has('programme_suggestion');
    $hideInstitutions = $abroad && ! $errors->has('scope_institutions');
    $typeLabels = \App\Models\Institution::TYPE_LABELS;
@endphp

<div class="card mb-4" data-level-needs="programmes" id="scope-card" data-open-to>
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">Who is it open to?</h2>
    </div>
    <div class="card-body">
        <p class="small text-secondary">
            ScholarFit uses your answer to tell each student whether they qualify, so it is required for Certificate,
            Diploma, degree and postgraduate awards. A student must fit the programmes <em>or</em> fields you choose,
            <em>and</em> study at one of the institutions you choose, if you choose any.
        </p>

        <fieldset class="mb-3">
            <legend class="form-label fs-6 mb-1">What it is open to</legend>
            <div class="form-check">
                <input class="form-check-input @error('open_to') is-invalid @enderror" type="radio" name="open_to" id="open-to-chosen"
                       value="{{ \App\Services\Catalogue\ListingScopes::OPEN_CHOSEN }}" @checked($openTo === \App\Services\Catalogue\ListingScopes::OPEN_CHOSEN)>
                <label class="form-check-label" for="open-to-chosen">Only the programmes or fields I choose below</label>
            </div>
            <div class="form-check">
                <input class="form-check-input @error('open_to') is-invalid @enderror" type="radio" name="open_to" id="open-to-all"
                       value="{{ \App\Services\Catalogue\ListingScopes::OPEN_ALL }}" @checked($openTo === \App\Services\Catalogue\ListingScopes::OPEN_ALL)>
                <label class="form-check-label" for="open-to-all">All programmes at this level (for example, a top-achievers award)</label>
            </div>
            @error('open_to')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </fieldset>

        <div class="alert alert-info small" data-abroad-only role="note" @unless($abroad) hidden @endunless>
            This award is for study outside Zimbabwe. Our programme and institution lists hold Zimbabwean ones only, so
            choose the <strong>fields</strong> it is open to (for example ICT), and write the universities under
            "Host universities". A student's own programme is matched to these fields.
        </div>

        <div data-open-chosen @if($openTo === \App\Services\Catalogue\ListingScopes::OPEN_ALL) hidden @endif>
<div class="mb-3" data-catalogue-filter data-home-only @if($hideProgrammes) hidden @endif>
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
                {{-- A broad field with one narrow field of the same name is listed once: see Field::pickerGroups. --}}
                @foreach(\App\Models\Field::pickerGroups($catalogue['fields'], $chosen['fields']) as $group)
                    <optgroup label="{{ $group['label'] }}">
                        @foreach($group['options'] as $option)
                            <option value="{{ $option['id'] }}" data-search="{{ $option['search'] }}"
                                    @if($option['code'] === \App\Support\FormOptions::ENGINEERING_BROAD) data-covers="engineering" @endif
                                    @if($option['code'] === \App\Support\FormOptions::ENGINEERING_NARROW) data-hint="engineering" @endif
                                    @selected($option['selected'])>{{ $option['label'] }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <div class="form-text" id="scope-fields-help">
                Choose a field to open the award to every programme in it, so you do not have to tick them one by one.
                Abroad, this is how students are matched.
            </div>
            {{--
                Civil engineering is filed under Architecture and construction and mining under Manufacturing, so the
                narrow Engineering field leaves both out. Shown whenever it is chosen without the broad field above it;
                catalogue-filter.js keeps it in step as the choice changes, and with JavaScript off it is right on load.
            --}}
            @php
                $engineeringNarrow = $catalogue['fields']->flatMap->children->firstWhere('code', \App\Support\FormOptions::ENGINEERING_NARROW);
                $engineeringBroad = $catalogue['fields']->firstWhere('code', \App\Support\FormOptions::ENGINEERING_BROAD);
                $showEngineeringHint = $engineeringNarrow && $engineeringBroad
                    && in_array($engineeringNarrow->id, $chosen['fields'], true)
                    && ! in_array($engineeringBroad->id, $chosen['fields'], true);
            @endphp
            @if($engineeringNarrow && $engineeringBroad)
                <div class="form-text text-warning-emphasis" id="scope-engineering-hint" data-hint-for="engineering" role="note" @unless($showEngineeringHint) hidden @endunless>
                    "{{ $engineeringNarrow->label() }}" on its own does not include civil engineering or mining engineering - civil and mining engineering are not included.
                    To include them, choose "All of {{ $engineeringBroad->label() }}" as well.
                </div>
            @endif
            @error('scope_fields')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            @foreach($errors->get('scope_fields.*') as $messages)<div class="invalid-feedback d-block">{{ $messages[0] }}</div>@endforeach
        </div>

        <details data-home-only @if(old('programme_suggestion')) open @endif @if($hideProgrammes) hidden @endif>
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

        <fieldset class="mb-3" data-home-only @if($hideInstitutions) hidden @endif>
            <legend class="form-label fs-6 mb-1">Institutions</legend>
            <div class="form-check">
                <input class="form-check-input @error('institutions_open_to') is-invalid @enderror" type="radio" name="institutions_open_to" id="institutions-any"
                       value="{{ \App\Services\Catalogue\ListingScopes::INSTITUTIONS_ANY }}" @checked($institutionsOpenTo === \App\Services\Catalogue\ListingScopes::INSTITUTIONS_ANY)>
                <label class="form-check-label" for="institutions-any">Any institution</label>
            </div>
            <div class="form-check">
                <input class="form-check-input @error('institutions_open_to') is-invalid @enderror" type="radio" name="institutions_open_to" id="institutions-chosen"
                       value="{{ \App\Services\Catalogue\ListingScopes::INSTITUTIONS_CHOSEN }}" @checked($institutionsOpenTo === \App\Services\Catalogue\ListingScopes::INSTITUTIONS_CHOSEN)>
                <label class="form-check-label" for="institutions-chosen">Only students at the institutions I choose</label>
            </div>
            @error('institutions_open_to')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

<div class="mt-2" data-catalogue-filter data-institutions-chosen @if($institutionsOpenTo === \App\Services\Catalogue\ListingScopes::INSTITUTIONS_ANY) hidden @endif>
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
            <div class="form-text">The institutions this scholarship pays for.</div>
            @error('scope_institutions')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        </fieldset>

        <div class="mb-3" data-abroad-only @unless($abroad) hidden @endunless>
            <x-form.input name="host_institutions" label="Host universities (optional)"
                          :value="$opportunity?->host_institutions"
                          hint="Where students will study, for example: University of Edinburgh, UCL. Shown to students; ScholarFit does not check it." />
        </div>

        <div class="mb-3" data-level-needs="olevel">
            <x-form.checkbox name="accepts_o_level" wrapper-class="mb-0"
                             label="O-Level holders may apply"
                             hint="Leave unticked if students need A-Level, or a Certificate or Diploma, first. ScholarFit then tells O-Level students this degree award is not for them yet."
                             :checked="(bool) ($opportunity?->accepts_o_level ?? false)" />
        </div>

    </div>
</div>
