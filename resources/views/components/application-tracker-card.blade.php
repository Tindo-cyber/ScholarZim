@props(['application'])

@php
    $title = $application->opportunity?->title ?? 'Removed listing';
@endphp

{{-- One application, as a card with its progress rail. The rail comes from Application::trackerStages(). --}}
<div class="card mb-3" data-sz-tracker-card>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
            <div>
                <h3 class="h6 fw-semibold mb-0">{{ $title }}</h3>
                <span class="small text-secondary">
                    {{ $application->opportunity?->awardingBody() }}
                    <span class="sz-tabular">&middot; Application #{{ $application->application_id }}</span>
                </span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />
                <a class="btn btn-sm btn-outline-secondary"
                   href="{{ route('applications.confirmation', $application->application_id) }}">View details</a>
            </div>
        </div>

        <x-timeline :stages="$application->trackerStages()" :label="'Progress of your application to ' . $title" />

        @if($application->isDecided() && $application->decision_reason)
            <p class="small text-secondary mt-3 mb-0"><strong>Provider's reason:</strong> {{ $application->decision_reason }}</p>
        @endif
    </div>
</div>
