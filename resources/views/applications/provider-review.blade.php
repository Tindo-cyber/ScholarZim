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

            @if($application->info_request)
                {{--
                    The question and its answer sit above the profile: when a
                    provider comes back to this page it is usually because the
                    applicant replied, so the reply is what they are looking for.
                --}}
                <div class="card mb-4 {{ $application->info_responded_at && ! $awaitingResponse ? 'border-primary' : '' }}">
                    <div class="card-header d-flex align-items-center gap-2">
                        <x-icon name="chat" :size="18" />
                        <h2 class="h6 fw-semibold mb-0">What you asked for</h2>
                        @if($awaitingResponse)
                            <x-status-badge label="Awaiting reply" tone="warning" class="ms-auto" />
                        @elseif($application->info_responded_at)
                            <x-status-badge label="Answered" tone="primary" class="ms-auto" />
                        @endif
                    </div>
                    <div class="card-body">
                        <blockquote class="border-start border-3 ps-3 mb-3">
                            <p class="mb-1">{{ $application->info_request }}</p>
                            <footer class="small text-secondary">
                                You, {{ $application->info_requested_at?->diffForHumans() }}
                            </footer>
                        </blockquote>

                        @if($application->info_response && ! $awaitingResponse)
                            <blockquote class="border-start border-3 border-primary ps-3 mb-0">
                                <p class="mb-1">{{ $application->info_response }}</p>
                                <footer class="small text-secondary">
                                    {{ $application->user?->displayName() ?? 'The applicant' }},
                                    {{ $application->info_responded_at?->diffForHumans() }}
                                </footer>
                            </blockquote>
                        @else
                            <p class="small text-secondary mb-0">
                                The applicant has been notified and has not replied yet.
                            </p>
                        @endif
                    </div>
                </div>
            @endif

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
                                'Citizenship' => $applicantProfile->citizenship,
                                'Age' => $applicantProfile->age(),
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
                                   href="{{ route('files.applicationDocument', $application->application_id) }}"
                                   target="_blank" rel="noopener">
                                    <x-icon name="eye" :size="14" />{{ $application->document_filename }}
                                </a>
                            @endif

                            @if($applicantProfile->hasResultsCertificate())
                                <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                   href="{{ route('files.applicantResults', $application->application_id) }}"
                                   target="_blank" rel="noopener">
                                    <x-icon name="eye" :size="14" />Results certificate
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
                    @if($canAward)
                        {{--
                            Approved, and not yet awarded. This is the only place
                            the award can be granted from, and the only state it
                            can be granted in - the server enforces both, this
                            just stops offering a button that would be refused.
                        --}}
                        <p class="small text-secondary">
                            You approved this applicant. Awarding the scholarship records the grant against
                            their application and tells them it is theirs.
                        </p>
                        <form method="POST" action="{{ route('provider.applications.award', $application->application_id) }}">
                            @csrf
                            <button class="btn btn-success w-100 d-inline-flex align-items-center justify-content-center gap-2"
                                    type="submit">
                                <x-icon name="stars" :size="16" /> Award this scholarship
                            </button>
                        </form>
                    @elseif($application->isAwarded())
                        <p class="mb-0">
                            <span class="fw-semibold d-block mb-1">Scholarship awarded</span>
                            <span class="text-secondary small">
                                Granted {{ $application->awarded_at?->format('d M Y') ?? 'recently' }}. An award is
                                final &mdash; this application cannot be moved again.
                            </span>
                        </p>
                    @elseif($statuses === [])
                        {{--
                            Rejected or withdrawn: the lifecycle is over, so there
                            is no decision left to take and the form would only
                            offer moves the server refuses.
                        --}}
                        <p class="text-secondary mb-0">
                            This application is {{ strtolower($application->statusLabel()) }} and can no longer be changed.
                        </p>
                    @else
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
                                         hint="Required when approving, rejecting, or asking the applicant for anything. They see it verbatim." />

                        <button class="btn btn-primary w-100" type="submit">Save decision</button>
                    </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

@endsection
