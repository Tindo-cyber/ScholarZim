@extends('layouts.app')

@section('title', 'Search')

@section('content')

    <x-page-header title="Search"
                   :subtitle="$term !== '' ? $results['total'] . ' result(s) for &quot;' . $term . '&quot;' : 'Search across users, listings, and applications.'"
                   eyebrow="Administration" />

    <form method="GET" action="{{ route('admin.search') }}" class="card mb-4">
        <div class="card-body d-flex gap-2">
            <input type="search" class="form-control" name="q" value="{{ $term }}"
                   placeholder="Name, email, or scholarship title" aria-label="Search" autofocus>
            <button class="btn btn-primary px-4" type="submit">Search</button>
        </div>
    </form>

    @if($term === '')
        <div class="card">
            <x-empty-state title="Start typing"
                           message="Search matches user names and emails, scholarship titles and providers, and applications by either."
                           icon="search" />
        </div>
    @elseif($results['total'] === 0)
        <div class="card">
            <x-empty-state title="Nothing found" :message="'No records match &quot;' . $term . '&quot;.'" icon="search" />
        </div>
    @else
        <div class="row g-4">

            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Users ({{ $results['users']->count() }})</h2>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse($results['users'] as $user)
                            <li class="list-group-item d-flex align-items-center gap-2">
                                <x-avatar :user="$user" size="sm" />
                                <div class="min-w-0 flex-grow-1">
                                    <span class="fw-semibold d-block text-truncate">{{ $user->displayName() }}</span>
                                    <span class="small text-secondary">{{ $user->email }}</span>
                                </div>
                                <x-status-badge :label="\App\Support\RoleNames::displayLabel($user->roleName())" tone="secondary" />
                            </li>
                        @empty
                            <li class="list-group-item text-secondary small">No matching users.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Scholarships ({{ $results['opportunities']->count() }})</h2>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse($results['opportunities'] as $opportunity)
                            <li class="list-group-item">
                                <a class="fw-semibold d-block text-body text-decoration-none"
                                   href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">
                                    {{ $opportunity->title }}
                                </a>
                                <span class="small text-secondary d-block">{{ $opportunity->awardingBody() }}</span>
                                <x-status-badge :label="$opportunity->lifecycleLabel()"
                                                :tone="$opportunity->lifecycleTone()" class="mt-1" />
                            </li>
                        @empty
                            <li class="list-group-item text-secondary small">No matching scholarships.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Applications ({{ $results['applications']->count() }})</h2>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse($results['applications'] as $application)
                            <li class="list-group-item">
                                <span class="fw-semibold d-block">{{ $application->user?->displayName() ?? 'Deleted user' }}</span>
                                <span class="small text-secondary d-block">{{ $application->opportunity?->title }}</span>
                                <x-status-badge :label="$application->statusLabel()"
                                                :tone="$application->statusTone()" class="mt-1" />
                            </li>
                        @empty
                            <li class="list-group-item text-secondary small">No matching applications.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    @endif

@endsection
