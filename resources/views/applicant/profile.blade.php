@extends('layouts.app')

@section('title', 'My profile')

@section('content')

    <x-page-header title="My profile"
                   subtitle="Everything ScholarFit uses to score scholarships against you."
                   eyebrow="Student" />

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
                                <x-form.input name="full_name" label="Full name" :value="auth()->user()->full_name" required />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="phone" label="Phone number" type="tel" :value="auth()->user()->phone" />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="date_of_birth" label="Date of birth" type="date"
                                              :value="$profile->date_of_birth?->format('Y-m-d')"
                                              max="{{ now()->subYears(12)->toDateString() }}"
                                              hint="Some awards have an age limit. Without this we cannot check one for you." />
                            </div>
                            <div class="col-md-6">
                                <fieldset class="mb-3">
                                    <legend class="form-label mb-2">Gender</legend>
                                    @foreach($genders as $genderValue => $genderLabel)
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio"
                                                   id="field-gender-{{ $genderValue }}"
                                                   name="gender" value="{{ $genderValue }}"
                                                   @checked(old('gender', $profile->gender) === $genderValue)>
                                            <label class="form-check-label" for="field-gender-{{ $genderValue }}">
                                                {{ $genderLabel }}
                                            </label>
                                        </div>
                                    @endforeach
                                    @error('gender')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                    <div class="form-text">
                                        Optional. Shown to providers reviewing your application; it does not
                                        affect your ScholarFit score or which awards you are eligible for.
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
                                <x-form.select name="education_level" label="Current education level"
                                               :options="$educationLevels" :grouped="true"
                                               :value="$profile->education_level"
                                               placeholder="Select your level"
                                               id="sz-education-level" />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="institution_name" label="Institution / school"
                                              :value="$profile->institution_name"
                                              list="institution-list"
                                              hint="Type your school, college or university - it does not need to be on the list." />
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
                                <x-form.select name="field_of_study" label="Field of study / programme"
                                               :options="$fields" :value="$profile->field_of_study"
                                               placeholder="Select a field" />
                            </div>
                            <div class="col-md-4">
                                <x-form.input name="year_of_study" label="Year of study" type="number"
                                              min="1" max="8" :value="$profile->year_of_study"
                                              hint="If relevant to your programme." />
                            </div>
                            <div class="col-md-8">
                                {{-- A degree class is one fact about a person, not a score per
                                     module, so it is held here rather than as subject results.
                                     That also keeps it out of any A-Level points total. --}}
                                <x-form.select name="degree_classification" label="Degree classification"
                                               :options="collect($degreeClassifications)->mapWithKeys(fn ($c) => [$c => $c])->all()"
                                               :value="$profile->degree_classification"
                                               placeholder="Not yet classified"
                                               hint="Your award classification, if you have completed or been graded." />
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
                            Some awards are limited to one province, or aimed at rural or urban applicants.
                            ScholarFit uses this to match you; nothing here is shown publicly.
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

                {{-- Primary only: the guardian-assisted Form 1 pathway. --}}
                <div class="card mb-4" data-sz-tier="PRIMARY" id="sz-guardian-card">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Guardian details</h2>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info small">
                            ScholarZim is for students transitioning into Form 1. A Primary applicant
                            applies with a parent or guardian's involvement - add their details below.
                            You will only be able to apply to scholarships for Form 1 entry.
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <x-form.input name="guardian_name" label="Guardian full name"
                                              :value="$profile->guardian_name" />
                            </div>
                            <div class="col-md-4">
                                <x-form.input name="guardian_phone" label="Guardian phone number" type="tel"
                                              :value="$profile->guardian_phone" />
                            </div>
                            <div class="col-md-4">
                                <x-form.input name="guardian_relationship" label="Relationship to applicant"
                                              :value="$profile->guardian_relationship"
                                              hint="For example: Mother, Father, Aunt, Guardian." />
                            </div>
                        </div>
                        <x-form.checkbox name="guardian_confirmed" wrapper-class=""
                                         label="I confirm a parent or guardian is aware of and involved in this application."
                                         :checked="$profile->guardian_confirmed_at !== null" />
                    </div>
                </div>

                <div class="card mb-4" id="academic-results">
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
                                        You recorded your results before we asked for them subject by subject.
                                        We have not filled anything in for you, because we would have to guess
                                        at your grades. Add each subject below and your points will be worked
                                        out automatically. <strong>Scholarships cannot check your results until
                                        you do.</strong>
                                    </p>
                                    <p class="small text-secondary mb-1">What you wrote before:</p>
                                    <blockquote class="small fst-italic border-start border-2 ps-3 mb-0">
                                        {{ $profile->legacyAcademicResults() }}
                                    </blockquote>
                                </div>
                            </div>
                        @endif

                        <p class="text-secondary small">
                            Enter your subjects and grades exactly as they appear on your results slip.
                            ScholarZim works out any points itself - you never type a points total, and
                            grades from one examination board are never converted into another's.
                        </p>

                        @error('academic_subject_results')
                            <div class="alert alert-danger small">{{ $message }}</div>
                        @enderror
                        @foreach($errors->get('academic_subject_results.*') as $messages)
                            <div class="alert alert-danger small">{{ $messages[0] }}</div>
                        @endforeach

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
                                    @foreach($profile->academicResults as $idx => $result)
                                        <tr class="academic-result-row">
                                            <td data-label="Qualification">
                                                <select class="form-select form-select-sm qualification-select"
                                                        name="academic_subject_results[{{ $idx }}][qualification_id]"
                                                        aria-label="Qualification">
                                                    @foreach($qualifications as $qual)
                                                        <option value="{{ $qual->id }}"
                                                            @selected($qual->id == $result->qualification_id)>{{ $qual->name }}</option>
                                                    @endforeach
                                                </select>
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
                                                <select class="form-select form-select-sm subject-select"
                                                        name="academic_subject_results[{{ $idx }}][subject_id]"
                                                        data-selected="{{ $result->subject_id }}"
                                                        aria-label="Subject">
                                                    <option value="">Select subject</option>
                                                    @foreach(($result->qualification?->activeSubjects ?? []) as $subject)
                                                        <option value="{{ $subject->id }}"
                                                            @selected($subject->id == $result->subject_id)>{{ $subject->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td data-label="Result">
                                                <select class="form-select form-select-sm grade-select"
                                                        name="academic_subject_results[{{ $idx }}][result]"
                                                        data-selected="{{ $result->result }}"
                                                        aria-label="Result">
                                                    <option value="">Select result</option>
                                                    @foreach(($result->subject?->grades() ?? $result->qualification?->grades() ?? []) as $grade)
                                                        <option value="{{ $grade }}"
                                                            @selected($grade === $result->result)>{{ $grade }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td data-label="Year">
                                                <input type="number" class="form-control form-control-sm year-input"
                                                       name="academic_subject_results[{{ $idx }}][year]"
                                                       value="{{ $result->year }}"
                                                       min="1950" max="{{ now()->year + 1 }}" step="1"
                                                       aria-label="Year">
                                            </td>
                                            {{-- Derived, never entered. There is no input here on purpose. --}}
                                            <td class="text-end derived-points font-monospace" data-label="Points">
                                                {{ $result->points() !== null ? rtrim(rtrim(number_format($result->points(), 2), '0'), '.') : '—' }}
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
                                            <option value="{{ $qual->id }}">{{ $qual->name }}</option>
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
                            Primary applicants apply through the guardian-assisted Form 1 pathway. You may record
                            your Grade 7 learning areas above, but no result is required to start.
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
        </div>

        <div class="col-xl-4">

            {{-- The same component the dashboard and the matches page use, in its
                 checklist density. One definition of "complete", one list of what is
                 missing, and one set of links at the fields that close the gaps. --}}
            <x-profile-progress :profile="$profile" variant="checklist" class="mb-4" />

            <div class="card" id="documents" data-sz-tier-hide="PRIMARY">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Documents</h2>
                </div>

                <div class="card-body d-grid gap-4">
                    @php $required = $profile->requiredDocumentTypes(); @endphp

                    @if(count($required) > 1)
                        <div class="alert alert-warning small mb-0">
                            All four documents below are required for your education level.
                        </div>
                    @endif

                    <p class="small text-secondary mb-0">
                        Uploading again replaces what is there. Your documents are private: only you,
                        and a provider you have applied to, can open them.
                    </p>

                    @foreach([
                        'results' => ['Worth 5 points of your ScholarFit score.'],
                        'transcript' => ['The academic evidence providers and ScholarFit rely on above O-Level/A-Level.'],
                        'cv' => ['Most providers expect one.'],
                        'passport' => ['Used to confirm your identity and nationality.'],
                        'recommendation' => ['Supporting reference from a teacher or employer.'],
                    ] as $type => [$help])
                        @php
                            $label = \App\Models\ApplicantProfile::DOCUMENT_LABELS[$type];
                            $filename = $profile->documentFilename($type);
                            $uploadedAt = $profile->documentUploadedAt($type);
                            $isRequired = in_array($type, $required, true);
                            // A school-level profile never needs a transcript, and a
                            // tertiary-or-above profile never needs a results
                            // certificate - showing the one that does not apply to this
                            // education level at all would just be another unrelated
                            // field on the page.
                            $appliesToThisLevel = in_array($type, ['cv', 'passport', 'recommendation'], true)
                                || $isRequired;
                        @endphp

                        @if($appliesToThisLevel)
                            <div>
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                    <span class="fw-semibold small">{{ $label }}</span>
                                    @if($filename)
                                        <x-status-badge label="Uploaded" tone="success" icon="check" />
                                    @elseif($isRequired)
                                        <x-status-badge label="Required" tone="danger" />
                                    @else
                                        <x-status-badge label="Not uploaded" tone="secondary" />
                                    @endif
                                </div>

                                <p class="small text-secondary mb-2">
                                    {{ $isRequired ? 'Required before you can apply. ' : 'Optional, but recommended. ' }}{{ $help }}
                                </p>

                                @if($filename)
                                    <p class="small mb-2">
                                        <a class="text-decoration-none d-inline-flex align-items-center gap-1"
                                           href="{{ route('files.myDocument', $type) }}"
                                           target="_blank" rel="noopener">
                                            <x-icon name="eye" :size="14" />{{ $filename }}
                                        </a>
                                        <span class="text-secondary d-block">
                                            Uploaded {{ $uploadedAt?->diffForHumans() }}
                                        </span>
                                    </p>
                                @endif

                                <form method="POST" action="{{ route('applicant.profile.documents', $type) }}"
                                      enctype="multipart/form-data" class="d-flex gap-2">
                                    @csrf
                                    <input class="form-control form-control-sm" type="file" name="document"
                                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required
                                           aria-label="Upload {{ $label }}">
                                    <x-submit-button :label="$filename ? 'Replace' : 'Upload'"
                                                     busy-label="Uploading..."
                                                     tone="outline-primary" size="sm"
                                                     class="flex-shrink-0" />
                                </form>

                                <p class="form-text mt-1 mb-0">PDF, Word, JPG or PNG.</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>

@endsection
