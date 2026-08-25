@extends('layouts.app')

@section('title', 'Search alerts')

@section('content')

    <x-page-header title="Search alerts"
                   subtitle="Save a search and we will tell you when a new scholarship matches it."
                   eyebrow="Applicant">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('opportunities.index') }}">
                <x-icon name="search" :size="16" /> Build a new search
            </a>
        </x-slot:actions>
    </x-page-header>

    @if($searches->isEmpty())
        <div class="card">
            <x-empty-state title="No saved searches yet"
                           message="Filter the scholarship list to what you are actually looking for, then press 'Alert me about this search'. We check once a day."
                           icon="bell"
                           action-label="Browse scholarships"
                           :action-href="route('opportunities.index')" />
        </div>
    @else
        <div class="row g-3">
            @foreach($searches as $search)
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column gap-3">
                            <div class="d-flex align-items-start gap-2">
                                <div class="min-w-0 flex-grow-1">
                                    <h2 class="h6 fw-bold mb-1">{{ $search->name }}</h2>
                                    <p class="small text-secondary mb-0">{{ $search->summary() }}</p>
                                </div>

                                <x-status-badge :label="$search->alerts_enabled ? 'Alerts on' : 'Alerts off'"
                                                :tone="$search->alerts_enabled ? 'success' : 'secondary'"
                                                :icon="$search->alerts_enabled ? 'bell' : null" />
                            </div>

                            <p class="small text-secondary mb-0">
                                @if($search->last_alerted_at)
                                    Last alert {{ $search->last_alerted_at->diffForHumans() }}.
                                @else
                                    No matches since you saved this.
                                @endif
                            </p>

                            <div class="d-flex flex-wrap gap-2 mt-auto">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="{{ route('opportunities.index', $search->activeFilters()) }}">
                                    Run this search
                                </a>

                                <form method="POST" action="{{ route('applicant.savedSearches.toggle', $search->saved_search_id) }}" class="m-0">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">
                                        {{ $search->alerts_enabled ? 'Turn alerts off' : 'Turn alerts on' }}
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('applicant.savedSearches.destroy', $search->saved_search_id) }}"
                                      class="m-0 ms-auto">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit"
                                            aria-label="Delete the saved search {{ $search->name }}">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="small text-secondary mt-4 mb-0">
            You can keep up to {{ $maxSearches }} saved searches. Alerts are sent once a day and only cover
            listings published after you saved the search, so you are never sent the back catalogue. Email
            delivery follows your
            <a href="{{ route('account.security') }}">notification preferences</a>; the in-app alert arrives
            either way.
        </p>
    @endif

@endsection
