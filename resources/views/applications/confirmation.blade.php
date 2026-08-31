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

            {{--
                The decision first, because it is the only thing a student comes
                back to this page for. Both outcomes read the same way: what
                happened, and the provider's reason for it, word for word.
            --}}
            @if($application->isAccepted())
                <div class="alert alert-success">
                    <h2 class="h6 fw-semibold mb-1">Congratulations! Your application has been accepted.</h2>
                    <p class="mb-0">
                        {{ $application->opportunity?->awardingBody() ?? 'The provider' }} has granted you this
                        scholarship{{ $application->decided_at ? ' on ' . $application->decided_at->format('d F Y') : '' }}.
                        They will be in touch about what happens next.
                    </p>
                </div>
            @elseif($application->isRejected())
                <div class="alert alert-danger">
                    <h2 class="h6 fw-semibold mb-1">Your application was not successful.</h2>
                    <p class="mb-0">
                        This decision is final for this scholarship, but there are others open to you.
                    </p>
                </div>
            @elseif($application->isWithdrawn())
                <div class="alert alert-secondary">
                    <h2 class="h6 fw-semibold mb-1">You withdrew this application.</h2>
                    <p class="mb-0">
                        The provider was notified. You can apply again while the scholarship is still open.
                    </p>
                </div>
            @else
                <div class="alert alert-primary">
                    <h2 class="h6 fw-semibold mb-1">Your application is pending.</h2>
                    <p class="mb-0">
                        The provider is reviewing it. You will be notified as soon as they decide.
                    </p>
                </div>
            @endif

            @if($application->isDecided() && $application->decision_reason)
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Reason from the provider</h2>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">{{ $application->decision_reason }}</p>
                    </div>
                </div>
            @endif

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
                                           maxlength="500" placeholder="e.g. I accepted another scholarship">
                                </div>
                                <button class="btn btn-danger" type="submit">Yes, withdraw this application</button>
                            </form>
                        </div>
                    </div>
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
