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

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Contact details</h2>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <x-form.input name="full_name" label="Full name" :value="auth()->user()->full_name" required />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="phone" label="Phone number" type="tel" :value="auth()->user()->phone" />
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
                        </div>

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
                            <div class="col-md-6">
                                <x-form.input name="date_of_birth" label="Date of birth" type="date"
                                              :value="$profile->date_of_birth?->format('Y-m-d')"
                                              max="{{ now()->toDateString() }}"
                                              hint="Some awards have an age limit. Without this we cannot check one for you." />
                            </div>
                            <div class="col-md-6">
                                <x-form.select name="citizenship" label="Citizenship"
                                               :options="$citizenships" :value="$profile->citizenship"
                                               placeholder="Select"
                                               hint="Most ScholarZim awards are open to Zimbabwean citizens; some regional awards are not restricted." />
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
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="field-guardian_confirmed"
                                   name="guardian_confirmed" value="1"
                                   @checked(old('guardian_confirmed', $profile->guardian_confirmed_at !== null))>
                            <label class="form-check-label small" for="field-guardian_confirmed">
                                I confirm a parent or guardian is aware of and involved in this application.
                            </label>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Academic information</h2>
                    </div>
                    <div class="card-body">
                        {{-- O-Level / A-Level: a school-style results summary. Hidden entirely for Primary. --}}
                        <div data-sz-tier="SECONDARY" id="sz-school-results">
                            <x-form.textarea name="academic_results" label="O-Level / A-Level results"
                                             :value="$profile->academic_results" :rows="3"
                                             hint="For example: 12 points at A-Level (Maths A, Physics B, Chemistry B), or your O-Level subjects and grades." />
                        </div>

                        {{-- Tertiary and postgraduate: a transcript is the evidence, not a typed summary. --}}
                        <div data-sz-tier="TERTIARY,POSTGRADUATE" id="sz-tertiary-results">
                            <x-form.textarea name="academic_results" label="Academic performance (optional summary)"
                                             :value="$profile->academic_results" :rows="3"
                                             hint="Optional: a short note on your results, e.g. class of degree. Your transcript (uploaded under Documents) is the evidence providers rely on - ScholarZim does not use a GPA figure." />
                        </div>

                        <div data-sz-tier="PRIMARY" class="text-secondary small mb-0">
                            No academic results are needed yet - a guardian's involvement is what this
                            pathway asks for. See the Guardian details section above.
                        </div>

                        <x-form.textarea name="biography" label="Short biography"
                                         :value="$profile->biography" :rows="5"
                                         hint="Providers read this alongside your applications." />

                        <button class="btn btn-primary" type="submit">Save profile</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-xl-4">

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Profile completion</h2>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <x-match-score :score="$profile->completionPercentage()"
                                       size="lg"
                                       :label="$profile->isComplete() ? 'Complete' : 'In progress'" />

                        <p class="small text-secondary mb-0">
                            @if($profile->isComplete())
                                Every field ScholarFit reads is filled in. Your matches are as accurate as we
                                can make them.
                            @else
                                Each item below is a field ScholarFit scores you on. Filling them in raises your
                                match on every listing at once.
                            @endif
                        </p>
                    </div>

                    {{--
                        The same checklist the reminder job reads, so the nudge email
                        and this page can never disagree about what is missing.
                    --}}
                    <ul class="list-unstyled d-grid gap-2 mb-0">
                        @foreach($profile->completionChecklist() as $item)
                            <li class="sz-fit-reason small">
                                <x-icon :name="$item['done'] ? 'check-circle' : 'circle'" :size="16"
                                        class="text-{{ $item['done'] ? 'success' : 'secondary' }} mt-1" />
                                <span class="min-w-0">
                                    @if($item['done'])
                                        <span class="fw-semibold">{{ $item['label'] }}</span>
                                    @else
                                        <a class="fw-semibold"
                                           href="#{{ $item['anchor'] === 'documents' ? 'documents' : ($item['anchor'] === 'guardian' ? 'sz-guardian-card' : 'field-' . $item['anchor']) }}">
                                            {{ $item['label'] }}
                                        </a>
                                        <span class="d-block text-secondary">{{ $item['hint'] }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

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
                                        <x-status-badge label="Missing" tone="secondary" />
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
                                    <button class="btn btn-sm btn-outline-primary flex-shrink-0" type="submit">
                                        {{ $filename ? 'Replace' : 'Upload' }}
                                    </button>
                                </form>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>

@endsection
