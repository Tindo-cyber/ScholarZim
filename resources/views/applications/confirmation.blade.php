@extends('layouts.app')

@section('title', 'Application status')

@section('content')

    <x-page-header :title="$application->opportunity?->title ?? 'Application'"
                   :subtitle="'Submitted ' . ($application->submitted_at?->format('d M Y') ?? 'recently')"
                   eyebrow="Application status">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('applications.mine') }}">All my applications</a>
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
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">Your submission</h2>
                    <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />
                </div>
                <div class="card-body">
                    @if($application->personal_statement)
                        <h3 class="h6 fw-semibold text-uppercase text-secondary small mb-2">Personal statement</h3>
                        <div class="mb-4">{!! nl2br(e($application->personal_statement)) !!}</div>
                    @else
                        <p class="text-secondary">
                            This was a quick application, submitted using your profile details only.
                        </p>
                    @endif

                    @if($application->document_filename)
                        <h3 class="h6 fw-semibold text-uppercase text-secondary small mb-2">Attached document</h3>
                        <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                           href="{{ route('files.applicationDocument', $application->application_id) }}"
                           target="_blank" rel="noopener">
                            <x-icon name="eye" :size="14" />{{ $application->document_filename }}
                        </a>
                    @endif
                </div>
            </div>

            @if($application->application_status === \App\Support\ApplicationStatus::INTERVIEW && $application->interview_at)
                <div class="alert alert-info">
                    <h3 class="h6 fw-semibold mb-1">You have been invited to interview</h3>
                    <p class="mb-1">
                        <strong>{{ $application->interview_at->format('l, d M Y \a\t g:i A') }}</strong>
                    </p>
                    @if($application->rejection_reason)
                        <p class="mb-0">{{ $application->rejection_reason }}</p>
                    @endif
                </div>
            @elseif($application->rejection_reason)
                <div class="alert alert-danger">
                    <h3 class="h6 fw-semibold mb-1">Feedback from the provider</h3>
                    <p class="mb-0">{{ $application->rejection_reason }}</p>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">The scholarship</h2>
                </div>
                <div class="card-body">
                    @if($application->opportunity)
                        <dl class="mb-3">
                            @foreach([
                                'Awarding body' => $application->opportunity->awardingBody(),
                                'Education level' => $application->opportunity->education_level,
                                'Field of study' => $application->opportunity->target_field,
                                'Funding' => $application->opportunity->funding_type,
                                'Deadline' => $application->opportunity->deadline?->format('d M Y'),
                            ] as $label => $value)
                                <dt class="small text-secondary fw-normal">{{ $label }}</dt>
                                <dd class="fw-semibold">{{ $value ?: 'Not specified' }}</dd>
                            @endforeach
                        </dl>

                        <a class="btn btn-outline-primary w-100"
                           href="{{ route('scholarships.show', $application->opportunity->opportunity_id) }}">
                            View listing
                        </a>
                    @else
                        <p class="text-secondary mb-0">This listing is no longer available.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

@endsection
