@extends('layouts.app')

@section('title', 'Search')

@section('content')

    <x-page-header title="Search"
                   :subtitle="$term !== ''
                       ? $results['total'] . ' result(s) for &quot;' . $term . '&quot;'
                       : 'Search across users, listings, and applications.'"
                   eyebrow="Administration" />

    <form method="GET" action="{{ route('admin.search') }}" class="card mb-4" role="search">
        <div class="card-body d-flex flex-wrap gap-2">
            <input type="search" class="form-control flex-grow-1" name="q" value="{{ $term }}"
                   placeholder="Name, email, or scholarship title" aria-label="Search" autofocus>
            <button class="btn btn-primary px-4" type="submit">Search</button>
        </div>
    </form>

    @if($term === '')
        <div class="card">
            <x-empty-state title="Start typing"
                           message="One search covers three things at once: user names and email addresses, scholarship titles and awarding bodies, and applications by either the applicant or the listing."
                           icon="search" />
        </div>
    @elseif($results['total'] === 0)
        <div class="card">
            <x-empty-state :title="'Nothing matches &quot;' . $term . '&quot;'"
                           message="Partial words work - try a surname, part of an email address, or a few words from a scholarship title. Search does not cover audit entries; the audit log has its own filters."
                           icon="search"
                           action-label="Open the audit log"
                           :action-href="route('admin.audit')" />
        </div>
    @else
        <div class="row g-4">

            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center gap-2">
                        <x-icon name="people" :size="16" class="text-secondary" />
                        <h2 class="h6 fw-semibold mb-0">Users</h2>
                        <span class="badge rounded-pill bg-body-secondary text-secondary ms-auto">{{ $results['users']->count() }}</span>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse($results['users'] as $user)
                            <li class="list-group-item">
                                <div class="d-flex align-items-center gap-2">
                                    <x-avatar :user="$user" size="sm" />
                                    <div class="min-w-0 flex-grow-1">
                                        <span class="fw-semibold d-block text-truncate">{{ $user->displayName() }}</span>
                                        <span class="small text-secondary d-block text-break">{{ $user->email }}</span>
                                    </div>
                                </div>

                                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                    <x-status-badge :label="\App\Support\RoleNames::displayLabel($user->roleName())" tone="secondary" icon="person" />
                                    <x-status-badge :label="\App\Support\AccountStatus::displayLabel($user->account_status)"
                                                    :tone="\App\Support\AccountStatus::badgeTone($user->account_status)" />
                                    {{--
                                        A hit used to be a dead end: the one thing
                                        an administrator searches a person by is
                                        their email, and finding them left no way
                                        to act on them. This is the users page
                                        already filtered to that account - an
                                        existing route and an existing filter, not
                                        a new screen.
                                    --}}
                                    <a class="btn btn-sm btn-outline-secondary ms-auto"
                                       href="{{ route('admin.users.index') }}?q={{ urlencode($user->email) }}">Manage</a>
                                </div>
                            </li>
                        @empty
                            <li class="list-group-item text-secondary small">No matching users.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center gap-2">
                        <x-icon name="stars" :size="16" class="text-secondary" />
                        <h2 class="h6 fw-semibold mb-0">Scholarships</h2>
                        <span class="badge rounded-pill bg-body-secondary text-secondary ms-auto">{{ $results['opportunities']->count() }}</span>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse($results['opportunities'] as $opportunity)
                            {{--
                                Search returns every listing, in any state; the
                                public page only serves the published ones. Sending
                                an unpublished hit to scholarships.show produced a
                                404 from the admin's own search results, so an
                                unpublished listing goes to the moderation view -
                                which is the page an administrator wants for it
                                anyway.
                            --}}
                            @php
                                $href = $opportunity->isPubliclyVisible()
                                    ? route('scholarships.show', $opportunity->opportunity_id)
                                    : route('admin.moderation.show', $opportunity->opportunity_id);
                            @endphp
                            <li class="list-group-item">
                                <a class="fw-semibold d-block text-body text-decoration-none" href="{{ $href }}">
                                    {{ $opportunity->title }}
                                </a>
                                <span class="small text-secondary d-block">{{ $opportunity->awardingBody() }}</span>

                                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                    <x-status-badge :label="$opportunity->lifecycleLabel()"
                                                    :tone="$opportunity->lifecycleTone()" />
                                    <a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ $href }}">
                                        {{ $opportunity->isPubliclyVisible() ? 'View' : 'Review' }}
                                    </a>
                                </div>
                            </li>
                        @empty
                            <li class="list-group-item text-secondary small">No matching scholarships.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center gap-2">
                        <x-icon name="file-text" :size="16" class="text-secondary" />
                        <h2 class="h6 fw-semibold mb-0">Applications</h2>
                        <span class="badge rounded-pill bg-body-secondary text-secondary ms-auto">{{ $results['applications']->count() }}</span>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse($results['applications'] as $application)
                            <li class="list-group-item">
                                <span class="fw-semibold d-block">{{ $application->user?->displayName() ?? 'Deleted user' }}</span>
                                <span class="small text-secondary d-block">{{ $application->opportunity?->title ?? 'Deleted listing' }}</span>

                                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                    <x-status-badge :label="$application->statusLabel()"
                                                    :tone="$application->statusTone()" />
                                    {{--
                                        No action button. An application is decided
                                        by the provider who posted the listing, and
                                        there is no administrator route into one -
                                        so the row says what it is and stops, rather
                                        than offering a link that would 403.
                                    --}}
                                    <span class="small text-secondary ms-auto sz-tabular">
                                        #{{ $application->application_id }}
                                        @if($application->submitted_at)
                                            &middot; {{ $application->submitted_at->format('d M Y') }}
                                        @endif
                                    </span>
                                </div>
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
