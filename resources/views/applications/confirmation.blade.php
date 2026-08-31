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

            @if($application->isAwarded())
                <div class="alert alert-success">
                    <h3 class="h6 fw-semibold mb-1">Scholarship awarded</h3>
                    <p class="mb-0">
                        Congratulations &mdash; this scholarship has been awarded to you{{ $application->awarded_at ? ' on ' . $application->awarded_at->format('d F Y') : '' }}.
                        The provider will be in touch about what happens next.
                    </p>
                </div>
            @elseif($application->application_status === \App\Support\ApplicationStatus::INTERVIEW && $application->interview_at)
                <div class="alert alert-info">
                    <h3 class="h6 fw-semibold mb-1">You have been invited to interview</h3>
                    <p class="mb-1">
                        <strong>{{ $application->interview_at->format('l, d M Y \a\t g:i A') }}</strong>
                    </p>
                    @if($application->rejection_reason)
                        <p class="mb-2">{{ $application->rejection_reason }}</p>
                    @endif

                    {{-- Times in the file are UTC, so the reader's calendar shows their own zone. --}}
                    <a class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1"
                       href="{{ route('applications.interview.ics', $application->application_id) }}">
                        <x-icon name="calendar" :size="14" /> Add to calendar
                    </a>
                </div>
            @elseif($application->rejection_reason)
                <div class="alert alert-danger">
                    <h3 class="h6 fw-semibold mb-1">Feedback from the provider</h3>
                    <p class="mb-0">{{ $application->rejection_reason }}</p>
                </div>
            @endif
        </div>

            @if($awaitingResponse)
                {{--
                    The provider asked for something. This is the whole exchange:
                    their question, and one box to answer it in - no email round
                    trip, and the application stays exactly where it is.
                --}}
                <div class="card border-warning mb-4">
                    <div class="card-header bg-warning-subtle d-flex align-items-center gap-2">
                        <x-icon name="chat" :size="18" />
                        <h2 class="h6 fw-semibold mb-0">
                            {{ $application->application_status === \App\Support\ApplicationStatus::DOCUMENTS_REQUESTED
                                ? 'The provider needs more documents'
                                : 'The provider has a question' }}
                        </h2>
                    </div>
                    <div class="card-body">
                        <blockquote class="border-start border-3 ps-3 mb-4">
                            <p class="mb-1">{{ $application->info_request }}</p>
                            <footer class="small text-secondary">
                                Asked {{ $application->info_requested_at?->diffForHumans() }}
                            </footer>
                        </blockquote>

                        <form method="POST" action="{{ route('applications.respond', $application->application_id) }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label" for="info-response">Your answer</label>
                                <textarea class="form-control @error('response') is-invalid @enderror"
                                          id="info-response" name="response" rows="4" required
                                          minlength="10" maxlength="3000"
                                          placeholder="Answer the question, or say when you will send what they asked for."></textarea>
                                @error('response')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            @if($application->application_status === \App\Support\ApplicationStatus::DOCUMENTS_REQUESTED)
                                <p class="form-text mb-3">
                                    Documents are uploaded on
                                    <a href="{{ route('applicant.profile') }}#documents">your profile</a>; the
                                    provider can see them as soon as they are there.
                                </p>
                            @endif

                            <button class="btn btn-primary" type="submit">Send response</button>
                        </form>
                    </div>
                </div>
            @elseif($application->info_response)
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Your conversation with the provider</h2>
                    </div>
                    <div class="card-body">
                        <blockquote class="border-start border-3 ps-3 mb-3">
                            <p class="mb-1">{{ $application->info_request }}</p>
                            <footer class="small text-secondary">
                                Provider, {{ $application->info_requested_at?->diffForHumans() }}
                            </footer>
                        </blockquote>
                        <blockquote class="border-start border-3 border-primary ps-3 mb-0">
                            <p class="mb-1">{{ $application->info_response }}</p>
                            <footer class="small text-secondary">
                                You, {{ $application->info_responded_at?->diffForHumans() }}
                            </footer>
                        </blockquote>
                    </div>
                </div>
            @endif

            @if($application->canBeWithdrawn())
                <div class="card border-0 bg-body-tertiary">
                    <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
                        <div class="min-w-0">
                            <h2 class="h6 fw-semibold mb-1">Changed your mind?</h2>
                            <p class="small text-secondary mb-0">
                                Withdrawing tells the provider you are no longer in the running. You can apply
                                again later while the scholarship is still open.
                            </p>
                        </div>
                        <button class="btn btn-outline-danger flex-shrink-0" type="button"
                                data-bs-toggle="collapse" data-bs-target="#withdraw-panel"
                                aria-expanded="false" aria-controls="withdraw-panel">
                            Withdraw application
                        </button>
                    </div>

                    <div class="collapse" id="withdraw-panel">
                        <div class="card-body border-top">
                            <form method="POST" action="{{ route('applications.withdraw', $application->application_id) }}">
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label" for="withdraw-reason">
                                        Why are you withdrawing? <span class="text-secondary">(optional)</span>
                                    </label>
                                    <input type="text" class="form-control" id="withdraw-reason" name="reason"
                                           maxlength="500" placeholder="e.g. I accepted another award">
                                </div>
                                <button class="btn btn-danger" type="submit">Yes, withdraw this application</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endif

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
