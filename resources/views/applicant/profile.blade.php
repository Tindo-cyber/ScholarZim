@extends('layouts.app')

@section('title', 'My profile')

@section('content')

    <x-page-header title="My profile"
                   subtitle="Everything ScholarFit uses to score scholarships against you."
                   eyebrow="Student" />

    <div class="row g-4">
        <div class="col-xl-8">

            <form method="POST" action="{{ route('applicant.profile.update') }}" novalidate>
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
                        <h2 class="h6 fw-semibold mb-0">Academic profile</h2>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <x-form.select name="education_level" label="Current education level"
                                               :options="$educationLevels" :grouped="true"
                                               :value="$profile->education_level"
                                               placeholder="Select your level" />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="institution_name" label="Institution"
                                              :value="$profile->institution_name"
                                              list="institution-list"
                                              hint="Start typing to pick from known institutions." />
                                <datalist id="institution-list">
                                    @foreach($institutions as $institution)
                                        <option value="{{ $institution }}"></option>
                                    @endforeach
                                </datalist>
                            </div>
                            <div class="col-md-6">
                                <x-form.select name="field_of_study" label="Field of study"
                                               :options="$fields" :value="$profile->field_of_study"
                                               placeholder="Select a field" />
                            </div>
                            <div class="col-md-3">
                                <x-form.select name="country" label="Country"
                                               :options="$countries"
                                               :value="$profile->country ?: \App\Support\FormOptions::DEFAULT_COUNTRY"
                                               placeholder="Select" />
                            </div>
                            <div class="col-md-3">
                                <x-form.select name="province" label="Province"
                                               :options="$provinces" :value="$profile->province"
                                               placeholder="Select" />
                            </div>
                            <div class="col-md-3">
                                <x-form.input name="district" label="District"
                                              :value="$profile->district"
                                              hint="Optional. Some awards target one district." />
                            </div>
                            <div class="col-md-3">
                                {{--
                                    Its own field, not a province. A number of
                                    Zimbabwean awards are aimed specifically at
                                    rural students, and until now there was no
                                    way for anyone to say which they are.
                                --}}
                                <x-form.select name="locality" label="Home area"
                                               :options="collect($localities)->mapWithKeys(fn ($l) => [$l => ucfirst(strtolower($l))])->all()"
                                               :value="$profile->locality"
                                               placeholder="Select"
                                               hint="Rural or urban - used only to match awards aimed at one or the other." />
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
                                               hint="Some awards are restricted to particular citizens." />
                            </div>
                        </div>

                        <div id="sz-academic-results-field" data-school-levels="{{ json_encode(\App\Support\FormOptions::schoolLevels()) }}">
                            <x-form.textarea name="academic_results" label="Academic results"
                                             :value="$profile->academic_results" :rows="3"
                                             hint="For example: 12 points at A-Level (Maths A, Physics B, Chemistry B)." />
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
                                           href="#{{ $item['anchor'] === 'documents' ? 'documents' : 'field-' . $item['anchor'] }}">
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

            <div class="card" id="documents">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Documents</h2>
                </div>

                <div class="card-body d-grid gap-4">
                    @if(!\App\Support\FormOptions::isSchoolLevel($profile->education_level) && filled($profile->education_level))
                        <div class="alert alert-warning small mb-0">
                            All four documents are required for your education level.
                        </div>
                    @endif

                    @php $required = $profile->requiredDocumentTypes(); @endphp

                    @foreach([
                        'results' => ['Worth 5 points of your ScholarFit score.'],
                        'cv' => ['Most providers expect one.'],
                        'passport' => ['Used to confirm your identity and nationality.'],
                        'recommendation' => ['Supporting reference from a teacher or employer.'],
                    ] as $type => [$help])
                        @php
                            $label = \App\Models\ApplicantProfile::DOCUMENT_LABELS[$type];
                            $filename = $profile->documentFilename($type);
                            $uploadedAt = $profile->documentUploadedAt($type);
                            $isRequired = in_array($type, $required, true);
                        @endphp

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
                    @endforeach
                </div>
            </div>
        </div>
    </div>

@endsection
