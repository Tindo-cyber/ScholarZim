@php
    /**
     * "Your programme": what an enrolled student is studying, or up to three programmes a
     * school-leaver hopes to study. Its own form (not part of the profile form above), saved on
     * its own, and shown only where a programme means something - never for a Primary pupil.
     *
     * The current-programme and institution pickers are plain <select>s; the pick-up-to-three lists are
     * checkboxes (x-limited-choice), because a multiple <select> needs Ctrl-click and nobody finds that out.
     * There is no "institutions you have applied to" list: it is asked for on the application that needs it.
     * Both work with JavaScript off; resources/js adds a search box over each list and, on the checkbox
     * lists, a running count that stops a fourth being ticked. The server refuses a fourth regardless.
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
            <h2 class="h6 fw-semibold mb-0">{{ $mode === 'current' ? 'What you want to study next' : 'What you hope to study' }}</h2>
        </div>
        <div class="card-body">
            @if($mode === 'current')
                <p class="small text-secondary">
                    Your current programme and institution are in the form above. Use this card for what you want to study next, or a programme that is not listed.
                </p>

                <x-limited-choice name="target_field_ids" id="target_field_ids" label="Fields you want to study next (optional)"
                                  :max="\App\Models\ApplicantTargetField::MAX" noun="fields"
                                  :groups="\App\Models\Field::pickerGroups($catalogue['fields'], array_map('intval', (array) old('target_field_ids', $targetFieldIds ?? [])))"
                                  hint="Scholarships open to these fields will match you." />
            @else
                <p class="small text-secondary">
                    Choose up to 3 programmes you hope to study. You match a scholarship if any one fits.
                </p>

                <x-limited-choice name="intended_programme_ids" id="intended_programme_ids" label="Programmes you hope to study"
                                  :max="\App\Models\ApplicantProgramme::MAX_INTENDED" noun="programmes"
                                  :groups="$options->map(fn ($programmes, $level) => [
                                      'label' => \App\Support\EducationLevel::label($level),
                                      'options' => $programmes->map(fn ($programme) => [
                                          'id' => $programme->id,
                                          'label' => $programme->name . ($programme->field ? ' · ' . $programme->field->label() : '') . ($programme->isPending() ? ' (waiting for approval)' : ''),
                                          'search' => strtolower($programme->name . ' ' . $programme->synonyms->pluck('synonym')->implode(' ') . ' ' . $programme->field?->label()),
                                          'selected' => in_array($programme->id, array_map('intval', (array) $intendedIds), true),
                                      ])->all(),
                                  ])->values()->all()" />
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
