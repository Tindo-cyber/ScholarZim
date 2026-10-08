@php
    /**
     * "Your programme": what an enrolled student is studying, or up to three programmes a
     * school-leaver hopes to study. Its own form (not part of the profile form above), saved on
     * its own, and shown only where a programme means something - never for a Primary pupil.
     *
     * Plain <select>s, so it works with JavaScript off; resources/js/catalogue-filter.js adds a
     * search box over each list.
     */
    $mode = \App\Services\Catalogue\ApplicantProgrammes::modeFor($profile);
    $levels = \App\Services\Catalogue\ApplicantProgrammes::levelsFor($profile);
@endphp

@if($mode !== \App\Services\Catalogue\ApplicantProgrammes::NONE)
    @php
        $options = $catalogue['programmes']->filter(fn ($p) => in_array($p->education_level, $levels, true))->groupBy('education_level');
        $currentRow = $programmeChoices->firstWhere('kind', 'current');
        $intendedIds = old('intended_programme_ids', $programmeChoices->where('kind', 'intended')->pluck('programme_id')->all());
        $currentId = (int) old('current_programme_id', $currentRow?->programme_id);
        $institutionId = (int) old('current_institution_id', $currentRow?->institution_id);
        $narrowFields = $catalogue['fields'];
    @endphp

    <form method="POST" action="{{ route('applicant.profile.programmes') }}" class="card mb-4" id="programme-card" novalidate>
        @csrf
        <div class="card-header">
            <h2 class="h6 fw-semibold mb-0">{{ $mode === 'current' ? 'Your programme' : 'What you hope to study' }}</h2>
        </div>
        <div class="card-body">
            @if($mode === 'current')
                <p class="small text-secondary">
                    Choose the programme you are studying, so scholarships for your programme or field can find you.
                    This is at {{ \App\Support\EducationLevel::label($profile->education_level) }} level, matching your level above
                    (change your level first, and save it, if that is not right).
                </p>

                <div class="mb-3" data-catalogue-filter>
                    <label class="form-label" for="current_programme_id">Programme</label>
                    <select class="form-select @error('current_programme_id') is-invalid @enderror" id="current_programme_id" name="current_programme_id">
                        <option value="">Not chosen</option>
                        @foreach($options as $level => $programmes)
                            @foreach($programmes as $programme)
                                <option value="{{ $programme->id }}" @selected($currentId === $programme->id)
                                        data-search="{{ strtolower($programme->name . ' ' . $programme->synonyms->pluck('synonym')->implode(' ')) }}">{{ $programme->name }}@if($programme->isPending()) (waiting for approval)@endif</option>
                            @endforeach
                        @endforeach
                    </select>
                    @error('current_programme_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="current_institution_id">Where you study (optional)</label>
                    <select class="form-select @error('current_institution_id') is-invalid @enderror" id="current_institution_id" name="current_institution_id">
                        <option value="">Not chosen</option>
                        @foreach($catalogue['institutions']->groupBy('type') as $type => $institutions)
                            <optgroup label="{{ \App\Models\Institution::TYPE_LABELS[$type] ?? $type }}">
                                @foreach($institutions as $institution)
                                    <option value="{{ $institution->id }}" @selected($institutionId === $institution->id)>{{ $institution->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('current_institution_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @else
                <p class="small text-secondary">
                    You have not started a programme yet. Choose up to 3 you hope to study, and you will be matched to a
                    scholarship if any one of them fits.
                </p>

                <div class="mb-3" data-catalogue-filter>
                    <label class="form-label" for="intended_programme_ids">Programmes (up to 3)</label>
                    <select class="form-select @error('intended_programme_ids') is-invalid @enderror" id="intended_programme_ids"
                            name="intended_programme_ids[]" multiple size="8">
                        @foreach($options as $level => $programmes)
                            <optgroup label="{{ \App\Support\EducationLevel::label($level) }}">
                                @foreach($programmes as $programme)
                                    <option value="{{ $programme->id }}" @selected(in_array($programme->id, array_map('intval', (array) $intendedIds), true))
                                            data-search="{{ strtolower($programme->name . ' ' . $programme->synonyms->pluck('synonym')->implode(' ')) }}">{{ $programme->name }}@if($programme->isPending()) (waiting for approval)@endif</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <div class="form-text">Hold Ctrl (or Cmd) to choose several. You can search by name or abbreviation.</div>
                    @error('intended_programme_ids')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3" data-catalogue-filter>
                    <label class="form-label" for="applied_institution_ids">Institutions you have applied to or have an offer from (optional, up to 3)</label>
                    <select class="form-select @error('applied_institution_ids') is-invalid @enderror" id="applied_institution_ids"
                            name="applied_institution_ids[]" multiple size="6">
                        @foreach($catalogue['institutions']->groupBy('type') as $type => $institutions)
                            <optgroup label="{{ \App\Models\Institution::TYPE_LABELS[$type] ?? $type }}">
                                @foreach($institutions as $institution)
                                    <option value="{{ $institution->id }}" data-search="{{ strtolower($institution->name . ' ' . $institution->code) }}"
                                            @selected(in_array($institution->id, array_map('intval', (array) old('applied_institution_ids', $appliedInstitutionIds)), true))>{{ $institution->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <div class="form-text">Some scholarships are only for students at certain institutions. This lets us tell you which ones you can apply for.</div>
                    @error('applied_institution_ids')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            @endif

            <details class="mb-3" @if(old('programme_suggestion')) open @endif>
                <summary class="small">My programme isn't listed</summary>
                <div class="row g-2 mt-1">
                    <div class="col-12">
                        <label class="form-label small" for="programme_suggestion">Programme name</label>
                        <input class="form-control @error('programme_suggestion') is-invalid @enderror" id="programme_suggestion"
                               name="programme_suggestion" maxlength="200" value="{{ old('programme_suggestion') }}">
                        @error('programme_suggestion')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="programme_suggestion_field">Its field</label>
                        <select class="form-select @error('programme_suggestion_field') is-invalid @enderror" id="programme_suggestion_field" name="programme_suggestion_field">
                            <option value="">Choose a field</option>
                            @foreach($narrowFields as $broad)
                                <optgroup label="{{ $broad->label() }}">
                                    @foreach($broad->children as $narrow)
                                        <option value="{{ $narrow->id }}" @selected((int) old('programme_suggestion_field') === $narrow->id)>{{ $narrow->label() }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('programme_suggestion_field')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @if($mode === 'intended')
                        <div class="col-md-6">
                            <label class="form-label small" for="programme_suggestion_level">Its level</label>
                            <select class="form-select @error('programme_suggestion_level') is-invalid @enderror" id="programme_suggestion_level" name="programme_suggestion_level">
                                <option value="">Choose a level</option>
                                @foreach($levels as $level)
                                    <option value="{{ $level }}" @selected(old('programme_suggestion_level') === $level)>{{ \App\Support\EducationLevel::label($level) }}</option>
                                @endforeach
                            </select>
                            @error('programme_suggestion_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endif
                </div>
                <p class="form-text">You can use it straight away. An administrator then checks it and adds it to the list.</p>
            </details>

            <x-submit-button label="Save programme" busy-label="Saving..." />
        </div>
    </form>
@endif
