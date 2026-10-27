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
     * Plain radios and checkbox lists, so the form works with JavaScript off. resources/js/scope-picker.js adds a search
     * box, the chosen list and the level filter, and resources/js/open-to.js hides what the answers make irrelevant; the
     * server checks the same things and drops anything an answer overrides (ListingScopes::answered).
     */
    $scopes = $opportunity?->scopes ?? collect();
    $chosen = [
        'programmes' => array_map('intval', (array) old('scope_programmes', $scopes->pluck('programme_id')->filter()->all())),
        'fields' => array_map('intval', (array) old('scope_fields', $scopes->pluck('field_id')->filter()->all())),
        'institutions' => array_map('intval', (array) old('scope_institutions', $scopes->pluck('institution_id')->filter()->all())),
    ];
    // Which way the provider says it is open: all programmes, specific programmes, or whole fields. A listing saved with
    // both programmes and fields (older ones) keeps "chosen", so editing it never silently drops half of what it was open to.
    $openTo = old('open_to', match (true) {
        $opportunity?->open_to_all_programmes === true => \App\Services\Catalogue\ListingScopes::OPEN_ALL,
        $opportunity?->open_to_all_programmes === false, $chosen['programmes'] !== [] || $chosen['fields'] !== [] => match (true) {
            $chosen['programmes'] !== [] && $chosen['fields'] !== [] => \App\Services\Catalogue\ListingScopes::OPEN_CHOSEN,
            $chosen['programmes'] !== [] => \App\Services\Catalogue\ListingScopes::OPEN_PROGRAMMES,
            $chosen['fields'] !== [] => \App\Services\Catalogue\ListingScopes::OPEN_FIELDS,
            default => null,
        },
        default => null,
    });
    // Posted lists without an answer (a form sent without its script) are read the same way the server reads them.
    if ($openTo === null && ($chosen['programmes'] !== [] || $chosen['fields'] !== [])) {
        $openTo = $chosen['programmes'] !== [] && $chosen['fields'] !== []
            ? \App\Services\Catalogue\ListingScopes::OPEN_CHOSEN
            : ($chosen['programmes'] !== [] ? \App\Services\Catalogue\ListingScopes::OPEN_PROGRAMMES : \App\Services\Catalogue\ListingScopes::OPEN_FIELDS);
    }
    $institutionsOpenTo = old('institutions_open_to', match (true) {
        $opportunity?->any_institution === true => \App\Services\Catalogue\ListingScopes::INSTITUTIONS_ANY,
        $opportunity?->any_institution === false, $chosen['institutions'] !== [] => \App\Services\Catalogue\ListingScopes::INSTITUTIONS_CHOSEN,
        default => null,
    });
    $abroad = \App\Services\Catalogue\ListingScopes::isAbroad(old('country', $opportunity?->country));
    // A list that has to be cleared before saving stays in view, so its error can be read and acted on.
    $hideProgrammes = $abroad && ! $errors->has('scope_programmes') && ! $errors->has('programme_suggestion');
    $hideInstitutions = $abroad && ! $errors->has('scope_institutions');

    $listingScopes = \App\Services\Catalogue\ListingScopes::class;
    // A list the answer above makes irrelevant is folded away, unless it holds an error that has to be read and fixed.
    $hideProgrammesList = ($hideProgrammes || in_array($openTo, [$listingScopes::OPEN_ALL, $listingScopes::OPEN_FIELDS], true))
        && ! $errors->has('scope_programmes') && ! $errors->has('programme_suggestion') && ! $errors->has('programme_suggestion_field');
    $hideFieldsList = in_array($openTo, [$listingScopes::OPEN_ALL, $listingScopes::OPEN_PROGRAMMES], true) && ! $errors->has('scope_fields');

    $programmeGroups = \App\Services\Catalogue\ProgrammeCatalogue::pickerGroups($catalogue['programmes'], $chosen['programmes']);
    $activeLevel = \App\Support\EducationLevel::canonical(old('education_level', $opportunity?->education_level));
    $fieldGroups = \App\Models\Field::pickerGroupsByLevel($catalogue['fields'], $catalogue['programmes'], $chosen['fields'], $activeLevel);
    foreach ($fieldGroups as &$fieldGroup) {
        foreach ($fieldGroup['options'] as &$fieldOption) {
            // The narrow Engineering field leaves civil and mining out; open-to.js shows a note while only it is ticked.
            if ($fieldOption['code'] === \App\Support\FormOptions::ENGINEERING_BROAD) {
                $fieldOption['data'] = ['covers' => 'engineering'];
            } elseif ($fieldOption['code'] === \App\Support\FormOptions::ENGINEERING_NARROW) {
                $fieldOption['data'] = ['hint' => 'engineering'];
            }
        }
        unset($fieldOption);
    }
    unset($fieldGroup);
    $institutionGroups = \App\Models\Institution::pickerGroupsByLevel($catalogue['institutions'], $chosen['institutions'], $activeLevel);
@endphp

<div class="card mb-4" data-level-needs="programmes" id="scope-card" data-open-to>
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">Who is it open to?</h2>
    </div>
    <div class="card-body">
        <p class="small text-secondary">
            If this scholarship is for particular programmes or fields, choose them below
            or say so in the description. A scholarship for Medicine should say so - otherwise
            students whose subjects don't fit may apply, only to find the university will not
            admit them.
        </p>
        <p class="small text-secondary">
            Required for Certificate, Diploma, degree and postgraduate awards. ScholarFit uses these answers to check each student.
        </p>

        <fieldset class="mb-3">
            <legend class="form-label fs-6 mb-1">1. How widely does it apply?</legend>
            <div class="form-check mb-1">
                <input class="form-check-input @error('open_to') is-invalid @enderror" type="radio" name="open_to" id="open-to-all"
                       value="{{ $listingScopes::OPEN_ALL }}" @checked($openTo === $listingScopes::OPEN_ALL)>
                <label class="form-check-label" for="open-to-all">
                    All programmes at this level
                    <span class="d-block small text-secondary">Any eligible student at this level can apply, whatever they study. For example, a scholarship for all eligible undergraduates.</span>
                </label>
            </div>
            <div class="form-check mb-1" data-home-only @if($abroad) hidden @endif>
                <input class="form-check-input @error('open_to') is-invalid @enderror" type="radio" name="open_to" id="open-to-programmes"
                       value="{{ $listingScopes::OPEN_PROGRAMMES }}" @checked($openTo === $listingScopes::OPEN_PROGRAMMES)>
                <label class="form-check-label" for="open-to-programmes">
                    Specific programmes
                    <span class="d-block small text-secondary">Only students on the programmes you tick. Choosing Accounting does not open the award to the rest of its field.</span>
                </label>
            </div>
            <div class="form-check mb-1">
                <input class="form-check-input @error('open_to') is-invalid @enderror" type="radio" name="open_to" id="open-to-fields"
                       value="{{ $listingScopes::OPEN_FIELDS }}" @checked($openTo === $listingScopes::OPEN_FIELDS)>
                <label class="form-check-label" for="open-to-fields">
                    An entire field of study
                    <span class="d-block small text-secondary">Every programme in the field at this level, so you do not tick them one by one. For example, Computing and ICT.</span>
                </label>
            </div>
            @if($openTo === $listingScopes::OPEN_CHOSEN)
                <div class="form-check mb-1">
                    <input class="form-check-input" type="radio" name="open_to" id="open-to-chosen"
                           value="{{ $listingScopes::OPEN_CHOSEN }}" checked>
                    <label class="form-check-label" for="open-to-chosen">
                        Both specific programmes and whole fields
                        <span class="d-block small text-secondary">This listing was saved this way. Choose one of the options above to change it.</span>
                    </label>
                </div>
            @endif
            @error('open_to')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </fieldset>

        <div class="alert alert-info small" data-abroad-only role="note" @unless($abroad) hidden @endunless>
            For study outside Zimbabwe, choose <strong>An entire field of study</strong> and enter the universities under
            "Institution not listed?". Our programme and institution lists are Zimbabwean only.
            Fields are ISCED-F codes and are hierarchical: a broad field like "Health
            and welfare" includes Nursing, Pharmacy and Physiotherapy. If you need a
            specific degree (MBBS, not Nursing), choose the most detailed field
            available, or say so in "Other conditions".
        </div>

        <div data-open-programmes data-home-only @if($hideProgrammesList) hidden @endif>
            <x-scope-picker name="scope_programmes" id="scope-programmes" label="2. Which programmes?" noun="programmes"
                            level-select="field-education_level" :active-level="\App\Support\EducationLevel::canonical(old('education_level', $opportunity?->education_level))" :groups="$programmeGroups"
                            help="Search by name or abbreviation, e.g. BIS. Each programme is listed under its level and field." />

            <details class="mb-3" @if(old('programme_suggestion') || $errors->has('programme_suggestion') || $errors->has('programme_suggestion_field')) open @endif>
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
                            @foreach(\App\Models\Field::filingOptions() as $group)
                                <optgroup label="{{ $group['label'] }}">
                                    @foreach($group['fields'] as $option)
                                        <option value="{{ $option['id'] }}" @selected((int) old('programme_suggestion_field') === $option['id'])>{{ $option['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('programme_suggestion_field')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <p class="form-text">
                    Search the list first. A new programme is added at the level chosen above, used for this listing now,
                    and checked by an administrator.
                </p>
            </details>
        </div>

        <div data-open-fields @if($hideFieldsList) hidden @endif>
            {{-- A broad field with one narrow field of the same name is listed once: see Field::pickerGroups. --}}
            <x-scope-picker name="scope_fields" id="scope-fields" label="2. Which fields of study?" noun="fields"
                            level-select="field-education_level" :active-level="$activeLevel" level-noun="fields" :groups="$fieldGroups"
                            help="Fields that have programmes at the level chosen above. Tick a field to include every programme in it." />
            {{--
                Civil engineering is filed under Architecture and construction and mining under Manufacturing, so the
                narrow Engineering field leaves both out. Shown whenever it is chosen without the broad field above it;
                scope-picker.js does not know about it, so it is right on load and open-to.js keeps it in step.
            --}}
            @php
                $engineeringNarrow = $catalogue['fields']->flatMap->children->firstWhere('code', \App\Support\FormOptions::ENGINEERING_NARROW);
                $engineeringBroad = $catalogue['fields']->firstWhere('code', \App\Support\FormOptions::ENGINEERING_BROAD);
                $showEngineeringHint = $engineeringNarrow && $engineeringBroad
                    && in_array($engineeringNarrow->id, $chosen['fields'], true)
                    && ! in_array($engineeringBroad->id, $chosen['fields'], true);
            @endphp
            @if($engineeringNarrow && $engineeringBroad)
                <div class="form-text text-warning-emphasis mb-3" id="scope-engineering-hint" data-engineering-hint
                     data-narrow="{{ $engineeringNarrow->id }}" data-broad="{{ $engineeringBroad->id }}" role="note" @unless($showEngineeringHint) hidden @endunless>
                    "{{ $engineeringNarrow->label() }}" on its own does not include civil engineering or mining engineering - civil and mining engineering are not included.
                    To include them, also choose their own fields (Architecture and construction, and Manufacturing and processing).
                </div>
            @endif
        </div>

        <fieldset class="mb-3" data-home-only @if($hideInstitutions) hidden @endif>
            <legend class="form-label fs-6 mb-1">3. Where will the student study?</legend>
            <div class="form-check">
                <input class="form-check-input @error('institutions_open_to') is-invalid @enderror" type="radio" name="institutions_open_to" id="institutions-any"
                       value="{{ $listingScopes::INSTITUTIONS_ANY }}" @checked($institutionsOpenTo === $listingScopes::INSTITUTIONS_ANY)>
                <label class="form-check-label" for="institutions-any">Any institution</label>
            </div>
            <div class="form-check">
                <input class="form-check-input @error('institutions_open_to') is-invalid @enderror" type="radio" name="institutions_open_to" id="institutions-chosen"
                       value="{{ $listingScopes::INSTITUTIONS_CHOSEN }}" @checked($institutionsOpenTo === $listingScopes::INSTITUTIONS_CHOSEN)>
                <label class="form-check-label" for="institutions-chosen">At the institutions I choose</label>
            </div>
            @error('institutions_open_to')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

            <div class="mt-2" data-institutions-chosen @if($institutionsOpenTo === $listingScopes::INSTITUTIONS_ANY) hidden @endif>
                <x-scope-picker name="scope_institutions" id="scope-institutions" label="Institutions" noun="institutions"
                                level-select="field-education_level" :active-level="$activeLevel" level-noun="institutions" :groups="$institutionGroups"
                                help="Where the award is studied. Applicants do not need to be there already. Not listed? Use the option below." />
            </div>
        </fieldset>

        {{-- One copy, always in the page: for study in Zimbabwe it follows the institution list, for study abroad it replaces it. --}}
        @include('opportunities.partials.custom-institutions', ['opportunity' => $opportunity, 'idPrefix' => 'listing'])

        <div class="mb-3" data-abroad-only @unless($abroad) hidden @endunless>
            {{-- Listings saved before institutions could be entered one by one keep their wording; it is shown until replaced. --}}
            @if(filled(old('host_institutions', $opportunity?->host_institutions)))
                <x-form.input name="host_institutions" label="Host universities (earlier wording)"
                              :value="$opportunity?->host_institutions"
                              hint="Shown to students, not checked. Enter each university above instead, then clear this." />
            @endif
        </div>

        <div class="mb-3" data-level-needs="olevel">
            <x-form.checkbox name="accepts_o_level" wrapper-class="mb-0"
                             label="O-Level holders may apply"
                             hint="Leave unticked if students need A-Level or a Certificate or Diploma first."
                             :checked="(bool) ($opportunity?->accepts_o_level ?? false)" />
        </div>

    </div>
</div>
