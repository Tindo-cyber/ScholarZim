@extends('layouts.app')

@section('title', 'Review application')

@section('content')

    <x-page-header :title="$application->user?->displayName() ?? 'Applicant'"
                   :subtitle="'Applied to ' . ($application->opportunity?->title ?? 'a listing')"
                   eyebrow="Review application">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('provider.applications') }}">Back to inbox</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-4">
        <div class="card-body py-4">
            <x-timeline :stages="$timeline" />
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Personal statement</h2>
                </div>
                <div class="card-body">
                    @if($application->personal_statement)
                        {!! nl2br(e($application->personal_statement)) !!}
                    @else
                        <p class="text-secondary mb-0">
                            Quick application &mdash; no statement was submitted. The profile below is what
                            this applicant provided.
                        </p>
                    @endif
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Applicant profile</h2>
                </div>
                <div class="card-body">
                    @if($applicantProfile)
                        <dl class="row mb-3">
                            @foreach([
                                'Education level' => $applicantProfile->education_level,
                                'Institution' => $applicantProfile->institution_name,
                                'Field of study' => $applicantProfile->field_of_study,
                                'Country' => $applicantProfile->country,
                                'Province' => $applicantProfile->province,
                                'Academic results' => $applicantProfile->academic_results,
                            ] as $label => $value)
                                <dt class="col-sm-4 text-secondary fw-normal small">{{ $label }}</dt>
                                <dd class="col-sm-8 fw-semibold">{{ $value ?: 'Not provided' }}</dd>
                            @endforeach
                        </dl>

                        @if($applicantProfile->biography)
                            <h3 class="h6 fw-semibold text-uppercase text-secondary small mb-2">Biography</h3>
                            <p>{{ $applicantProfile->biography }}</p>
                        @endif

                        <div class="d-flex flex-wrap gap-2">
                            @if($application->document_filename)
                                <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                   href="{{ route('files.applicationDocument', $application->application_id) }}">
                                    <x-icon name="download" :size="14" />{{ $application->document_filename }}
                                </a>
                            @endif

                            @if($applicantProfile->hasResultsCertificate())
                                <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                   href="{{ route('files.applicantResults', $application->application_id) }}">
                                    <x-icon name="download" :size="14" />Results certificate
                                </a>
                            @endif
                        </div>
                    @else
                        <p class="text-secondary mb-0">This applicant has not completed a profile.</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card position-sticky" style="top: 5.5rem;">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">Decision</h2>
                    <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />
                </div>

                <div class="card-body">
                    <form method="POST" action="{{ route('provider.applications.review', $application->application_id) }}">
                        @csrf

                        <x-form.select name="status" label="Set status"
                                       :options="collect($statuses)->mapWithKeys(fn ($s) => [$s => \App\Support\ApplicationStatus::displayLabel($s)])->all()"
                                       :value="$application->application_status"
                                       :placeholder="null" required />

                        <div id="interview-at-field">
                            <x-form.input name="interview_at" label="Interview date and time" type="datetime-local"
                                          :value="$application->interview_at?->format('Y-m-d\TH:i')"
                                          hint="The applicant is notified of this date and time when you save." />
                        </div>

                        <x-form.textarea name="reason" label="Reason / message to applicant" :rows="4"
                                         :value="$application->rejection_reason"
                                         hint="Required when approving or rejecting. The applicant sees this verbatim." />

                        <button class="btn btn-primary w-100" type="submit">Save decision</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
    <script src="{{ asset('assets/js/application-review.js') }}"></script>
@endpush
