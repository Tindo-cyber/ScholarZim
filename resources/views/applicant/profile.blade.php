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
                                               :options="$countries" :value="$profile->country"
                                               placeholder="Select" />
                            </div>
                            <div class="col-md-3">
                                <x-form.select name="province" label="Province"
                                               :options="$provinces" :value="$profile->province"
                                               placeholder="Select" />
                            </div>
                        </div>

                        <x-form.textarea name="academic_results" label="Academic results"
                                         :value="$profile->academic_results" :rows="3"
                                         hint="For example: 12 points at A-Level (Maths A, Physics B, Chemistry B), or GPA 3.4." />

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
                <div class="card-body">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-secondary">Profile completion</span>
                        <span class="fw-semibold">{{ $profile->completionPercentage() }}%</span>
                    </div>
                    <div class="progress" style="height: .5rem;" role="progressbar"
                         aria-valuenow="{{ $profile->completionPercentage() }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: {{ $profile->completionPercentage() }}%"></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Documents</h2>
                </div>

                <div class="card-body d-grid gap-4">
                    @foreach([
                        'results' => ['Results certificate', 'Required before you can apply. Worth 5 points of your ScholarFit score.'],
                        'cv' => ['CV / resume', 'Optional, but most providers expect one.'],
                        'passport' => ['ID or passport', 'Used to confirm your identity and nationality.'],
                        'recommendation' => ['Recommendation letter', 'Optional supporting reference.'],
                    ] as $type => [$label, $help])
                        @php
                            $filename = $profile->documentFilename($type);
                            $uploadedAt = $profile->documentUploadedAt($type);
                        @endphp

                        <div>
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                <span class="fw-semibold small">{{ $label }}</span>
                                @if($filename)
                                    <x-status-badge label="Uploaded" tone="success" icon="check" />
                                @else
                                    <x-status-badge label="Missing" tone="secondary" />
                                @endif
                            </div>

                            <p class="small text-secondary mb-2">{{ $help }}</p>

                            @if($filename)
                                <p class="small mb-2">
                                    <a class="text-decoration-none d-inline-flex align-items-center gap-1"
                                       href="{{ route('files.myDocument', $type) }}">
                                        <x-icon name="download" :size="14" />{{ $filename }}
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
