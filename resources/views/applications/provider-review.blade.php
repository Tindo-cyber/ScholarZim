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

            @if($fit)
                {{--
                    Guidance, not a verdict. ScholarFit says how well this
                    applicant's profile lines up with what the listing asks for;
                    the decision below is entirely the provider's.
                --}}
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">ScholarFit match</h2>
                    </div>
                    <div class="card-body text-center">
                        <x-match-score :score="$fit->matchScore" :label="$fit->breakdown->confidenceLabel" />
                        <p class="small text-secondary mt-3 mb-0">{{ $fit->breakdown->explanation }}</p>
                        <p class="small text-secondary mt-2 mb-0">
                            A guide to how well the profile fits this listing. The decision is yours.
                        </p>
                    </div>
                </div>
            @endif

            <div class="card position-sticky" style="top: 5.5rem;">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">Decision</h2>
                    <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />
                </div>

                <div class="card-body">
                    @if($canDecide)
                        {{--
                            One form, two buttons. Accepting is granting the
                            scholarship, so there is nothing to do afterwards -
                            and both outcomes need the reason the applicant is
                            shown verbatim.
                        --}}
                        <form method="POST" action="{{ route('provider.applications.review', $application->application_id) }}">
                            @csrf

                            <x-form.textarea name="reason" label="Reason for your decision" :rows="4" required
                                             hint="The applicant sees this exactly as you write it." />

                            <div class="d-grid gap-2">
                                <button class="btn btn-success d-inline-flex align-items-center justify-content-center gap-2"
                                        type="submit" name="status"
                                        value="{{ \App\Support\ApplicationStatus::ACCEPTED }}">
                                    <x-icon name="check-circle" :size="16" /> Accept
                                </button>
                                <button class="btn btn-outline-danger d-inline-flex align-items-center justify-content-center gap-2"
                                        type="submit" name="status"
                                        value="{{ \App\Support\ApplicationStatus::REJECTED }}">
                                    <x-icon name="x-circle" :size="16" /> Reject
                                </button>
                            </div>
                        </form>
                    @else
                        <p class="mb-0">
                            <span class="fw-semibold d-block mb-1">
                                {{ $application->isAccepted() ? 'Scholarship granted' : $application->statusLabel() }}
                            </span>
                            <span class="text-secondary small">
                                @if($application->isDecided())
                                    Decided {{ $application->decided_at?->format('d M Y') ?? 'recently' }}.
                                    This is final &mdash; the application cannot be changed again.
                                @else
                                    The applicant withdrew this application, so there is no decision to make.
                                @endif
                            </span>
                        </p>

                        @if($application->decision_reason)
                            <hr>
                            <h3 class="h6 fw-semibold text-uppercase text-secondary small mb-1">Reason you gave</h3>
                            <p class="mb-0">{{ $application->decision_reason }}</p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

@endsection
