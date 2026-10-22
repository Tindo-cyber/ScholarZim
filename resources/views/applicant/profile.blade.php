@extends('layouts.app')

@section('title', 'My profile')

@section('content')

    <x-page-header title="My profile"
                   subtitle="Everything ScholarFit checks against a scholarship's requirements."
                   eyebrow="Student" />

    @if(($returnTo = request()->integer('apply')) > 0)
        {{-- Sent here from an application that needs something only the profile can hold (results, a programme).
             The way back is one click, and nothing is lost: what was saved here is read when the application reopens. --}}
        <div class="alert alert-info small d-flex flex-wrap align-items-center gap-2" role="note" id="return-to-application">
            <span>Add what is missing, save, then go back to your application.</span>
            <a class="btn btn-sm btn-primary ms-auto" href="{{ route('applications.wizard', $returnTo) }}">Back to my application</a>
        </div>
    @endif

    <x-profile-quality :profile="$profile" class="mb-4" />

    <div class="row g-4">
        <div class="col-xl-8">

            <form method="POST" action="{{ route('applicant.profile.update') }}" novalidate id="sz-profile-form">
                @csrf

                {{--
                    Four cards, in the order a student thinks about themselves:
                    who they are, what they are studying, where they live, what
                    they have passed. Date of birth and gender used to sit in
                    the Education card between "home area" and the guardian
                    section, which is where fields go when a form grows rather
                    than where a reader would look for them.
                --}}
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Personal information</h2>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <x-form.input name="full_name" label="Full name" :value="auth()->user()->full_name" required
                                              pattern="{{ \App\Support\FormOptions::NAME_INPUT_PATTERN }}" hint="Letters only - no numbers." data-name-input />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="phone" label="Phone number" type="tel" :value="auth()->user()->phone"
                                              inputmode="numeric" minlength="10" maxlength="10" pattern="\d{10}" data-phone-input="digits"
                                              hint="Optional. 10 digits, no spaces or country code, e.g. 0771234567." />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="date_of_birth" label="Date of birth" type="date"
                                              :value="$profile->date_of_birth?->format('Y-m-d')"
                                              max="{{ now()->subYears(12)->toDateString() }}"
                                              hint="Used to check age limits." />
                            </div>
                            <div class="col-md-6">
                                <fieldset class="mb-3">
                                    <legend class="form-label mb-2">Gender<span class="text-danger" aria-hidden="true">*</span></legend>
                                    @foreach($genders as $genderValue => $genderLabel)
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input @error('gender') is-invalid @enderror" type="radio"
                                                   id="field-gender-{{ $genderValue }}"
                                                   name="gender" value="{{ $genderValue }}" required
                                                   @checked(old('gender', $profile->gender) === $genderValue)>
                                            <label class="form-check-label" for="field-gender-{{ $genderValue }}">
                                                {{ $genderLabel }}
                                            </label>
                                        </div>
                                    @endforeach
                                    @error('gender')
                                        <div class="invalid-feedback d-block mt-1" id="gender-error">{{ $message }}</div>
                                    @enderror
                                    <div class="form-text">
                                        Required. Shown to providers; it does not affect eligibility.
                                    </div>
                                </fieldset>
                            </div>
                        </div>
                        <p class="small text-secondary mb-0">
                            Your email address ({{ auth()->user()->email }}) is managed from
                            <a href="{{ route('account.security') }}">Security &amp; privacy</a>.
                        </p>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Education</h2>
                    </div>
                    <div class="card-body">
                        {{--
                            Everything below the level select changes with it. This is not
                            cosmetic: field of study, results-style fields, and the guardian
                            section only make sense for some levels, and showing all of them
                            to everyone is the "giant form full of unrelated fields" the
                            profile used to be. sz-profile-form.js reads the same
                            data-tier attributes to toggle sections live; nothing here
                            depends on JavaScript to be correct on first load, only to be
                            responsive to a later change without a page reload.
                        --}}
                        <div class="row">
                            <div class="col-md-6">
                                <x-form.select name="education_level" label="Highest level you have completed"
                                               :options="$educationLevels" :grouped="true"
                                               hint="The highest level you have finished."
                                               :value="$profile->education_level"
                                               placeholder="Select your level"
                                               id="sz-education-level" />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="institution_name" label="Institution / school"
                                              :value="$profile->institution_name"
                                              list="institution-list"
                                              hint="Where you study. Pick from the list or type it." />
                                <datalist id="institution-list">
                                    @foreach($institutions as $institution)
                                        <option value="{{ $institution }}"></option>
                                    @endforeach
                                </datalist>
                            </div>
                        </div>

                        {{-- Tertiary and postgraduate only: field of study is not a meaningful question below this. --}}
                        <div data-sz-tier="TERTIARY,POSTGRADUATE" class="row" id="sz-field-of-study-row">
                            <div class="col-md-8">
                                {{--
                                    The field of study IS the programme: chosen from the catalogue at the applicant's own level
                                    (Certificate, Diploma, Undergraduate and so on), never typed. Saving writes the programme and its
                                    name to the profile together (ApplicantProfileService::update), so there is no second place to
                                    say it and no free-text answer the platform cannot match.
                                --}}
                                @php
                                    $fieldLevels = \App\Services\Catalogue\ApplicantProgrammes::levelsFor($profile);
                                    // Every programme is drawn, those that do not belong to the level in the form start hidden: choosing another
                                    // level in the form changes what is offered straight away (profile-form.js), without a save first.
                                    $fieldOptions = $catalogue['programmes'];
                                    $fieldChosen = (int) old('current_programme_id', $profile->current_programme_id);
                                @endphp
                                <div class="mb-3" id="field_of_study" data-catalogue-filter>
                                    <label class="form-label" for="current_programme_id">Field of study (your programme)</label>
                                    <select class="form-select @error('current_programme_id') is-invalid @enderror" id="current_programme_id" name="current_programme_id">
                                        <option value="">Not chosen</option>
                                        @foreach($fieldOptions as $programme)
                                            <option value="{{ $programme->id }}" @selected($fieldChosen === $programme->id)
                                                    data-level="{{ $programme->education_level }}"
                                                    @unless(in_array($programme->education_level, $fieldLevels, true) || $fieldChosen === $programme->id) hidden disabled @endunless
                                                    data-search="{{ strtolower($programme->name . ' ' . $programme->synonyms->pluck('synonym')->implode(' ') . ' ' . $programme->field?->label()) }}">{{ $programme->name }}@if($programme->field) &middot; {{ $programme->field->label() }}@endif @if($programme->isPending()) (waiting for approval)@endif</option>
                                        @endforeach
                                    </select>
                                    @error('current_programme_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    <div class="form-text">
                                        Choose your programme and we fill in its subject area for you. Not listed?
                                        <a href="#programme-card">Add it under "My programme isn't listed" in the next section</a>.
                                    </div>
                                    @if(filled($profile->field_of_study) && $fieldChosen === 0)
                                        <div class="form-text text-warning-emphasis">Earlier you wrote "{{ $profile->field_of_study }}". Please choose the matching programme.</div>
                                    @endif
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Where you live</h2>
                    </div>
                    <div class="card-body">
                        <p class="small text-secondary">
                            Some awards are limited to a province, or to rural or urban applicants. Not shown publicly.
                        </p>
                        <div class="row">
                            <div class="col-md-4">
                                <x-form.select name="province" label="Province"
                                               :options="$provinces" :value="$profile->province"
                                               placeholder="Select" />
                            </div>
                            <div class="col-md-4">
                                <x-form.input name="locality" label="Town / city (optional)"
                                              :value="$profile->locality"
                                              hint="For example: Gweru. Only some awards ask for this." />
                            </div>
                            <div class="col-md-4">
                                <x-form.select name="settlement_type" label="Home area (optional)"
                                               :options="collect($settlementTypes)->mapWithKeys(fn ($l) => [$l => ucfirst(strtolower($l))])->all()"
                                               :value="$profile->settlement_type"
                                               placeholder="Select"
                                               hint="Rural or urban - used only by awards aimed at one or the other." />
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Under 18 (or at school with no date of birth): shown and hidden live from the date of birth. --}}
                <div class="card mb-4" data-sz-minor id="sz-guardian-card" @if(! $profile->isMinor()) hidden @endif>
                    <span id="guardian"></span>
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Guardian details</h2>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info small">
                            You are under 18, so a parent or guardian must be involved in your applications. You can browse and
                            build your profile without these details, but they are needed when you submit an application. A
                            provider reviewing your application sees your guardian's name, phone number and relationship to you.
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <x-form.input name="guardian_name" label="Guardian full name"
                                              :value="$profile->guardian_name"
                                              pattern="{{ \App\Support\FormOptions::NAME_INPUT_PATTERN }}" hint="Letters only - no numbers." data-name-input />
                            </div>
                            <div class="col-md-4">
                                <x-form.input name="guardian_phone" label="Guardian phone number" type="tel"
                                              :value="$profile->guardian_phone"
                                              inputmode="numeric" minlength="10" maxlength="10" pattern="\d{10}" data-phone-input="digits"
                                              hint="10 digits, no spaces or country code, e.g. 0771234567." />
                            </div>
                            <div class="col-md-4">
                                <x-form.input name="guardian_relationship" label="Relationship to applicant"
                                              :value="$profile->guardian_relationship"
                                              hint="For example: Mother, Father, Aunt, Guardian." />
                            </div>
                        </div>
                        <input type="hidden" name="guardian_confirmed" value="0">
                        <x-form.checkbox name="guardian_confirmed" wrapper-class=""
                                         label="I confirm a parent or guardian is aware of and involved in my applications."
                                         :checked="$profile->guardian_confirmed_at !== null" />
                    </div>
                </div>

                @php
                    // Certificate and above are shown by their documents: the results section appears only when a scholarship the
                    // applicant could apply for asks for results (or they already hold some).
                    $documentsOnly = \App\Support\EducationLevel::needsNoAcademicDetail($profile->education_level);
                    $resultsAsked = $offeredQualificationIds !== [] || $profile->academicResults->isNotEmpty();
                @endphp
                <div class="card mb-4" id="academic-results" data-sz-level-hide="CERTIFICATE,DIPLOMA,UNDERGRADUATE,POSTGRADUATE,MASTERS,PHD"
                     data-sz-keep="{{ $resultsAsked ? '1' : '0' }}" @if($documentsOnly && ! $resultsAsked) hidden @endif>
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Academic information</h2>
                    </div>
                    <div class="card-body">
                        {{--
                            The marker that tells the server this submission carried the academic
                            section. Without it, ProfileController leaves academic results
                            untouched. It matters because an applicant who deletes their last row
                            submits no result rows at all, and that still has to be a deletion they
                            can make - testing for the rows themselves could not tell the two apart,
                            and the version before this one deleted everything on every save.
                        --}}
                        <input type="hidden" name="academic_results_submitted" value="1">

                        @if($profile->needsAcademicResultsMigration())
                            {{--
                                This applicant recorded their results before ScholarZim asked for them
                                subject by subject. The old text is shown back verbatim rather than
                                parsed: reading grades out of a sentence is guesswork, and guessing an
                                applicant's academic record wrong is worse than asking them to retype
                                four lines. Nothing is filled in for them.
                            --}}
                            <div class="alert alert-warning d-flex gap-2" role="note">
                                <x-icon name="person-exclamation" :size="18" class="flex-shrink-0 mt-1" />
                                <div>
                                    <strong class="d-block mb-1">Please re-enter your results below</strong>
                                    <p class="small mb-2">
                                        Please re-enter your results below, subject by subject. <strong>Scholarships cannot check them until you do.</strong>
                                    </p>
                                    <p class="small text-secondary mb-1">What you wrote before:</p>
                                    <blockquote class="small fst-italic border-start border-2 ps-3 mb-0">
                                        {{ $profile->legacyAcademicResults() }}
                                    </blockquote>
                                </div>
                            </div>
                        @endif

                        <p class="text-secondary small">
                            Enter your subjects and grades as on your results slip. Points are worked out for you.
                        </p>
                        <p class="text-secondary small">
                            Record the level you have completed. Earlier results appear only if a scholarship asks.
                        </p>


                        {{-- What is wrong with a row is said beside that row's own fields (and once in the summary at the top
                             of the page), never as a second alert here. Only a problem with the list as a whole is said here. --}}
                        @error('academic_subject_results')
                            <div class="text-danger small mb-2">{{ $message }}</div>
                        @enderror

                        @php
                            /*
                             * The rows to draw. After a refused save they are the rows the person submitted, in the
                             * order and with the numbers they had, so nothing they typed is lost and each message lands
                             * on the row it is about; otherwise they are what is saved on the profile.
                             */
                            $submittedRows = old('academic_results_submitted') ? old('academic_subject_results') : null;

                            if (is_array($submittedRows)) {
                                $academicRows = collect($submittedRows)->filter(fn ($row) => is_array($row))->map(function (array $row) {
                                    $qualification = filled($row['qualification_id'] ?? null)
                                        ? \App\Models\AcademicQualification::with('activeSubjects')->find($row['qualification_id'])
                                        : null;

                                    return [
                                        'qualification_id' => $row['qualification_id'] ?? null,
                                        'subject_id' => $row['subject_id'] ?? null,
                                        'result' => $row['result'] ?? null,
                                        'year' => $row['year'] ?? null,
                                        'qualification' => $qualification,
                                        'subject' => $qualification?->activeSubjects->firstWhere('id', (int) ($row['subject_id'] ?? 0)),
                                        'points' => null,
                                    ];
                                })->all();
                            } else {
                                $academicRows = $profile->academicResults->map(fn ($result) => [
                                    'qualification_id' => $result->qualification_id,
                                    'subject_id' => $result->subject_id,
                                    'result' => $result->result,
                                    'year' => $result->year,
                                    'qualification' => $result->qualification,
                                    'subject' => $result->subject,
                                    'points' => $result->points(),
                                ])->all();
                            }

                            $nextRowNumber = $academicRows === [] ? 0 : max(array_map('intval', array_keys($academicRows))) + 1;
                        @endphp

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-2 sz-table-stack" id="academic-results-table">
                                <thead>
                                    <tr>
                                        <th scope="col" style="width:26%">Qualification</th>
                                        <th scope="col" style="width:28%">Subject</th>
                                        <th scope="col" style="width:14%">Result</th>
                                        <th scope="col" style="width:14%">Year</th>
                                        <th scope="col" style="width:10%" class="text-end">Points</th>
                                        <th scope="col" style="width:8%"><span class="visually-hidden">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody id="academic-results-list">
                                    @foreach($academicRows as $idx => $row)
                                        @php
                                            $rowError = fn (string $field) => $errors->first("academic_subject_results.$idx.$field");
                                            // "Row 2: please select a subject." is how the summary says it; beside the field it is just the sentence.
                                            $rowMessage = function (string $field) use ($rowError) {
                                                $message = $rowError($field);

                                                return $message === '' ? null : ucfirst(preg_replace('/^Row \d+:\s*/', '', $message));
                                            };
                                        @endphp
                                        <tr class="academic-result-row">
                                            <td data-label="Qualification">
                                                <select class="form-select form-select-sm qualification-select @if($rowMessage('qualification_id')) is-invalid @endif"
                                                        name="academic_subject_results[{{ $idx }}][qualification_id]"
                                                        aria-label="Qualification" @if($rowMessage('qualification_id')) aria-invalid="true" @endif>
                                                    <option value="">Select qualification</option>
                                                    @foreach($qualifications as $qual)
                                                        @php
                                                            $isOffered = in_array((int) $qual->id, $offeredQualificationIds, true);
                                                            $isChosen = $qual->id == $row['qualification_id'];
                                                        @endphp
                                                        <option value="{{ $qual->id }}" data-level="{{ \App\Support\EducationLevel::canonical($qual->education_level) }}"
                                                            @if($isOffered) data-offered="1" @endif
                                                            @unless($isOffered || $isChosen) hidden disabled @endunless
                                                            @selected($isChosen)>{{ $qual->name }}</option>
                                                    @endforeach
                                                </select>
                                                @if($message = $rowMessage('qualification_id'))<div class="invalid-feedback d-block">{{ $message }}</div>@endif
                                            </td>
                                            {{--
                                                Options are rendered server-side, already selected, so an
                                                existing row is correct and submittable before the script
                                                runs. A row whose options only appear once JavaScript has
                                                populated them would submit an empty grade without it, and
                                                losing an applicant's results on save is exactly what this
                                                section was rebuilt to stop.
                                            --}}
                                            <td data-label="Subject">
                                                <select class="form-select form-select-sm subject-select @if($rowMessage('subject_id')) is-invalid @endif"
                                                        name="academic_subject_results[{{ $idx }}][subject_id]"
                                                        data-selected="{{ $row['subject_id'] }}"
                                                        aria-label="Subject" @if($rowMessage('subject_id')) aria-invalid="true" @endif>
                                                    <option value="">Select subject</option>
                                                    @foreach(($row['qualification']?->activeSubjects ?? []) as $subject)
                                                        <option value="{{ $subject->id }}"
                                                            @selected($subject->id == $row['subject_id'])>{{ $subject->label() }}</option>
                                                    @endforeach
                                                </select>
                                                @if($message = $rowMessage('subject_id'))<div class="invalid-feedback d-block">{{ $message }}</div>@endif
                                            </td>
                                            <td data-label="Result">
                                                <select class="form-select form-select-sm grade-select @if($rowMessage('result')) is-invalid @endif"
                                                        name="academic_subject_results[{{ $idx }}][result]"
                                                        data-selected="{{ $row['result'] }}"
                                                        aria-label="Result" @if($rowMessage('result')) aria-invalid="true" @endif>
                                                    <option value="">Select result</option>
                                                    @foreach(($row['subject']?->grades() ?? $row['qualification']?->grades() ?? []) as $grade)
                                                        <option value="{{ $grade }}"
                                                            @selected($grade === $row['result'])>{{ $grade }}</option>
                                                    @endforeach
                                                </select>
                                                @if($message = $rowMessage('result'))<div class="invalid-feedback d-block">{{ $message }}</div>@endif
                                            </td>
                                            <td data-label="Year">
                                                <input type="number" class="form-control form-control-sm year-input @if($rowMessage('year')) is-invalid @endif"
                                                       name="academic_subject_results[{{ $idx }}][year]"
                                                       value="{{ $row['year'] }}"
                                                       min="1950" max="{{ now()->year + 1 }}" step="1"
                                                       aria-label="Year" @if($rowMessage('year')) aria-invalid="true" @endif>
                                                @if($message = $rowMessage('year'))<div class="invalid-feedback d-block">{{ $message }}</div>@endif
                                            </td>
                                            {{-- Derived, never entered. There is no input here on purpose. --}}
                                            <td class="text-end derived-points font-monospace" data-label="Points">
                                                {{ $row['points'] !== null ? rtrim(rtrim(number_format($row['points'], 2), '0'), '.') : '—' }}
                                            </td>
                                            <td class="text-end" data-label="">
                                                <button type="button" class="btn btn-sm btn-outline-danger remove-row">Remove</button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <template id="academic-result-template">
                            <tr class="academic-result-row">
                                <td data-label="Qualification">
                                    <select class="form-select form-select-sm qualification-select"
                                            name="academic_subject_results[__IDX__][qualification_id]" aria-label="Qualification">
                                        <option value="">Select qualification</option>
                                        @foreach($qualifications as $qual)
                                            @php $isOffered = in_array((int) $qual->id, $offeredQualificationIds, true); @endphp
                                            <option value="{{ $qual->id }}" data-level="{{ \App\Support\EducationLevel::canonical($qual->education_level) }}"
                                                @if($isOffered) data-offered="1" @endif
                                                @unless($isOffered) hidden disabled @endunless>{{ $qual->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td data-label="Subject">
                                    <select class="form-select form-select-sm subject-select"
                                            name="academic_subject_results[__IDX__][subject_id]" aria-label="Subject" disabled></select>
                                </td>
                                <td data-label="Result">
                                    <select class="form-select form-select-sm grade-select"
                                            name="academic_subject_results[__IDX__][result]" aria-label="Result" disabled></select>
                                </td>
                                <td data-label="Year">
                                    <input type="number" class="form-control form-control-sm year-input"
                                           name="academic_subject_results[__IDX__][year]"
                                           min="1950" max="{{ now()->year + 1 }}" step="1" aria-label="Year">
                                </td>
                                <td class="text-end derived-points font-monospace" data-label="Points">—</td>
                                <td class="text-end" data-label="">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-row">Remove</button>
                                </td>
                            </tr>
                        </template>

                        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
                            <button type="button" id="add-academic-result" class="btn btn-sm btn-outline-secondary">
                                Add subject
                            </button>

                            {{--
                                Points are shown, never typed. There is no points input in
                                the grid above and no points field in what is submitted; the
                                server derives the figure from the grades, and this is the
                                same arithmetic run while the student is still working so
                                they are not surprised by it later.

                                academic-results.js writes the totals into the element
                                below and nothing else here; the label and the note are the
                                presentation it never had.
                            --}}
                            <div class="sz-points-panel">
                                <span class="sz-eyebrow mb-0">Calculated points</span>
                                <p class="small fw-semibold mb-0 sz-tabular" id="academic-points-summary" aria-live="polite"></p>
                                <span class="small text-secondary">Worked out from your grades. You cannot enter these yourself.</span>
                            </div>
                        </div>

                        <p class="text-danger small mt-2 mb-0 d-none" id="academic-duplicate-warning">
                            The same subject is listed twice under one qualification. Record each subject once.
                        </p>

                        <p class="text-secondary small mt-3 mb-0" data-sz-tier="PRIMARY">
                            You can record your Grade 7 results above. None is required to start.
                        </p>

                        <script type="application/json" id="academic-catalogue">@json($academicCatalogue)</script>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">About you</h2>
                    </div>
                    <div class="card-body">
                        <x-form.textarea name="biography" label="Short biography"
                                         :value="$profile->biography" :rows="5"
                                         hint="Providers read this alongside your applications." />

                        <x-submit-button label="Save profile" busy-label="Saving..." />
                    </div>
                </div>
            </form>

            @include('applicant.partials.programme-card', ['profile' => $profile, 'catalogue' => $catalogue, 'programmeChoices' => $programmeChoices, 'targetFieldIds' => $targetFieldIds ?? []])
        </div>

        <div class="col-xl-4">

            {{-- The same component the dashboard and the matches page use, in its
                 checklist density. One definition of "complete", one list of what is
                 missing, and one set of links at the fields that close the gaps. --}}
            <x-profile-progress :profile="$profile" variant="checklist" class="mb-4" />

            @if(\App\Support\EducationLevel::isPrimary($profile->education_level))
                {{--
                    A Grade 7 pupil is asked for no documents in general. The one thing they may offer is their
                    results slip, because a Form 1 award can ask to see it. Optional here; an award that requires it
                    asks for it when they apply.
                --}}
                @php
                    $slipName = $profile->documentFilename('grade7_slip');
                    $slipAt = $profile->documentUploadedAt('grade7_slip');
                @endphp
                <div class="card mb-4" id="grade7-slip-card" data-sz-tier="PRIMARY">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Grade 7 results slip</h2>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                            <span class="small text-secondary">Optional</span>
                            @if($slipName)
                                <x-status-badge label="Uploaded" tone="success" icon="check" />
                            @else
                                <x-status-badge label="Not uploaded" tone="secondary" />
                            @endif
                        </div>

                        <p class="small text-secondary">
                            Some Form 1 awards ask to see your Grade 7 results slip. A new upload replaces the current one.
                        </p>

                        @if($slipName)
                                <x-status-badge label="Uploaded" tone="success" icon="check" />
                            @else
                                <x-status-badge label="Not uploaded" tone="secondary" />
                            @endif
                        </div>

                        <p class="small text-secondary">
                            Some Form 1 awards ask for your Grade 7 slip. Uploading again replaces it. Only you and providers you apply to can open it.
                        </p>

                        @if($slipName)
                            <p class="small mb-2">
                                <a class="text-decoration-none d-inline-flex align-items-center gap-1"
                                   href="{{ route('files.myDocument', 'grade7_slip') }}" target="_blank" rel="noopener">
                                    <x-icon name="eye" :size="14" />{{ $slipName }}
                                </a>
                                <span class="text-secondary d-block">Uploaded {{ $slipAt?->diffForHumans() }}</span>
                            </p>
                        @endif

                        <form method="POST" action="{{ route('applicant.profile.documents', 'grade7_slip') }}"
                              enctype="multipart/form-data" class="d-flex gap-2">
                            @csrf
                            <input class="form-control form-control-sm @error('document') is-invalid @enderror" type="file" name="document"
                                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required aria-label="Upload your Grade 7 results slip">
                            <x-submit-button :label="$slipName ? 'Replace' : 'Upload'" busy-label="Uploading..."
                                             tone="outline-primary" size="sm" class="flex-shrink-0" />
                        </form>
                        @error('document')<div class="text-danger small mt-1">{{ $message }}</div>@enderror

                        <p class="form-text mt-1 mb-0">PDF, Word, JPG or PNG. Only you and providers you apply to can open it.</p>
                    </div>
                </div>
            @endif

            <div class="card" id="documents" data-sz-tier-hide="PRIMARY">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Documents</h2>
                </div>

                <div class="card-body d-grid gap-4">
                    @php
                        // One list, read from what is actually stored: the same one the apply wizard and the
                        // submission check use. Optional documents are listed below but never counted.
                        $checklist = $profile->documentChecklist();
                    @endphp

                    @if($checklist->requiredCount() > 0)
                        <div class="alert alert-{{ $checklist->isComplete() ? 'success' : 'warning' }} small mb-0" id="document-summary">
                            <div class="fw-semibold">{{ $checklist->requiredSentence() }}</div>
                            <div>{{ $checklist->progressSentence() }}</div>
                        </div>
                    @endif

                    <div id="document-instruction">
                        <p class="mb-1">Upload the required documents listed below.</p>
                        <p class="small text-secondary mb-0">Upload clear, readable copies. Existing documents will be reused when they meet the requirements.</p>
                    </div>

                    <section aria-labelledby="academic-documents-heading">
                        <h3 class="h6 fw-semibold mb-3" id="academic-documents-heading">Academic certificates</h3>

                        <div class="d-grid gap-3">
                            @foreach($checklist->group('academic') as $item)
                                @include('applicant.partials.document-row', ['item' => $item])
                            @endforeach
                        </div>
                    </section>

                    @if($checklist->group('other') !== [])
                        <section aria-labelledby="other-documents-heading">
                            <h3 class="h6 fw-semibold mb-3" id="other-documents-heading">Other documents</h3>

                            <div class="d-grid gap-3">
                                @foreach($checklist->group('other') as $item)
                                    @include('applicant.partials.document-row', ['item' => $item])
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @error('document')<div class="text-danger small">{{ $message }}</div>@enderror

                    <p class="form-text mb-0">
                        PDF, Word, JPG or PNG. A new upload replaces the current file. Only you and providers you apply to can open your documents.
                    </p>
                </div>
            </div>
        </div>
    </div>

@endsection
