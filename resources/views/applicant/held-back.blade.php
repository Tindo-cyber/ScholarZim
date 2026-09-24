@extends('layouts.app')

@section('title', 'Held back scholarships')

@section('content')

    <x-page-header title="Held back scholarships"
                   :subtitle="$heldBack->count() . ' scholarship(s) set aside, out of your active matches.'"
                   eyebrow="ScholarFit">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('applicant.recommendations') }}">Back to matches</a>
        </x-slot:actions>
    </x-page-header>

    @if($heldBack->isEmpty())
        <div class="card">
            <x-empty-state title="Nothing held back"
                           message="Hold back a scholarship from your matches and it will wait here, out of your active recommendations, until you bring it back."
                           icon="eye-off"
                           action-label="View my matches"
                           :action-href="route('applicant.recommendations')" />
        </div>
    @else
        <h2 class="visually-hidden">Held back scholarships</h2>

        <div class="d-grid gap-3">
            @foreach($heldBack as $entry)
                @continue($entry->opportunity === null)

                @php
                    $opportunity = $entry->opportunity;
                    $acceptedApplication = $accepted[$opportunity->opportunity_id] ?? null;
                    $hasApplied = in_array($opportunity->opportunity_id, $appliedIds, true);
                @endphp

                <article class="card sz-match-card">
                    <div class="card-body">
                        <div class="mb-2">
                            <h3 class="h6 fw-bold mb-1">
                                <a class="text-body text-decoration-none stretched-link"
                                   href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">
                                    {{ $opportunity->title }}
                                </a>
                            </h3>
                            <p class="small text-secondary mb-0">{{ $opportunity->awardingBody() }}</p>
                        </div>

                        <ul class="list-unstyled d-flex flex-wrap gap-3 small text-secondary mb-3">
                            <li class="d-flex align-items-center gap-1">
                                <x-icon name="calendar" :size="14" />
                                {{ $opportunity->deadline?->format('d M Y') ?? 'No deadline' }}
                            </li>
                            @if($opportunity->formattedAward())
                                <li class="d-flex align-items-center gap-1">
                                    <x-icon name="coins" :size="14" />{{ $opportunity->formattedAward() }}
                                </li>
                            @endif
                            <li class="d-flex align-items-center gap-1">
                                <x-icon name="eye-off" :size="14" />
                                Held back {{ $entry->held_back_at?->diffForHumans() }}
                            </li>
                        </ul>

                        <div class="d-flex flex-wrap gap-2 position-relative z-1">
                            @if($acceptedApplication)
                                <a class="text-decoration-none"
                                   href="{{ route('applications.confirmation', $acceptedApplication->application_id) }}">
                                    <x-status-badge label="Accepted" tone="success" />
                                </a>
                            @elseif($hasApplied)
                                <x-status-badge label="Applied" tone="success" />
                            @else
                                <a class="btn btn-primary btn-sm"
                                   href="{{ route('applications.wizard', $opportunity->opportunity_id) }}">Apply</a>
                            @endif

                            <a class="btn btn-outline-secondary btn-sm"
                               href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">View details</a>

                            <form method="POST" class="m-0 ms-auto"
                                  action="{{ route('applicant.held-back.destroy', $opportunity->opportunity_id) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                        type="submit"
                                        aria-label="Bring {{ $opportunity->title }} back into my active matches">
                                    <x-icon name="eye" :size="14" />
                                    Unhold
                                </button>
                            </form>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

@endsection
