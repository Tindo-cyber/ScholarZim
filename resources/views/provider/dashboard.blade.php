@extends('layouts.app')

@section('title', 'Provider dashboard')

@section('content')

    <x-page-header :title="$greeting" subtitle="Your listings and the applications they are attracting.">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('provider.applications') }}">View applications</a>
            @if(auth()->user()->isActive())
                <a class="btn btn-primary" href="{{ route('opportunities.create') }}">Post a scholarship</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @unless(auth()->user()->isActive())
        <div class="alert alert-warning">
            <h2 class="h6 fw-semibold mb-1">
                Your account is {{ strtolower(\App\Support\AccountStatus::displayLabel(auth()->user()->account_status)) }}
            </h2>
            <p class="mb-0">
                @if($providerProfile?->rejection_reason)
                    {{ $providerProfile->rejection_reason }}
                @else
                    An administrator is reviewing your registration certificate. You can publish scholarships
                    as soon as your organisation is verified.
                @endif
            </p>
        </div>
    @endunless

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-stat-card label="Listings" :value="$stats['totalOpportunities']" icon="stars" tone="primary" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Live" :value="$stats['liveOpportunities']" icon="check-circle" tone="success"
                         :hint="$stats['awaitingReview'] . ' awaiting review'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Applications" :value="$stats['applicationsReceived']" icon="inbox" tone="info"
                         :href="route('provider.applications')" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Awaiting decision" :value="$stats['pendingApplications']" icon="hourglass-split" tone="warning" />
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            {{-- The sidebar's "My listings" points here: there is no separate index
                 route for a provider's own listings, and inventing one would be a
                 feature, not navigation. --}}
            <div class="card mb-4" id="listings">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">My listings</h2>
                    @if(auth()->user()->isActive())
                        <a class="small text-decoration-none" href="{{ route('opportunities.create') }}">Post another</a>
                    @endif
                </div>

                @if($opportunities->isEmpty())
                    <x-empty-state title="No listings yet"
                                   message="Post your first scholarship and it goes live once an administrator approves it."
                                   icon="stars"
                                   :action-label="auth()->user()->isActive() ? 'Post a scholarship' : null"
                                   :action-href="auth()->user()->isActive() ? route('opportunities.create') : null" />
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Scholarship</th>
                                    <th scope="col">Deadline</th>
                                    <th scope="col">Applications</th>
                                    <th scope="col">Review state</th>
                                    <th scope="col" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($opportunities as $opportunity)
                                    <tr>
                                        <td>
                                            <span class="fw-semibold d-block">{{ $opportunity->title }}</span>
                                            <span class="small text-secondary">{{ $opportunity->target_field ?: 'Any field' }}</span>
                                        </td>
                                        <td class="text-secondary small">
                                            {{ $opportunity->deadline?->format('d M Y') ?? 'Rolling' }}
                                        </td>
                                        <td>
                                            <span class="badge bg-body-secondary text-body">
                                                {{ $opportunity->applications_count }}
                                            </span>
                                        </td>
                                        <td>
                                            <x-status-badge :label="$opportunity->lifecycleLabel()"
                                                            :tone="$opportunity->lifecycleTone()" />
                                            @if(strcasecmp((string) $opportunity->status, \App\Support\OpportunityStatus::CLOSED) === 0)
                                                <x-status-badge label="Archived" tone="secondary" icon="lock" />
                                            @endif
                                            @if($opportunity->rejection_reason)
                                                <span class="d-block small text-secondary mt-1">
                                                    {{ $opportunity->rejection_reason }}
                                                </span>
                                            @endif
                                            @if($opportunity->last_change_reason)
                                                <span class="d-block small text-secondary mt-1">
                                                    Last change: {{ $opportunity->last_change_reason }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @unless($opportunity->isWithdrawn())
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                                            type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                        Manage
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li>
                                                            <a class="dropdown-item"
                                                               href="{{ route('opportunities.edit', $opportunity->opportunity_id) }}">
                                                                Edit
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <button class="dropdown-item" type="button"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#extend-deadline-{{ $opportunity->opportunity_id }}">
                                                                Extend deadline
                                                            </button>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <button class="dropdown-item text-danger" type="button"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#withdraw-{{ $opportunity->opportunity_id }}">
                                                                Withdraw
                                                            </button>
                                                        </li>
                                                    </ul>
                                                </div>

                                                {{-- Both dialogs are opened from the dropdown above rather
                                                     than by their own trigger, so they are rendered here on
                                                     their own and the menu items keep their data-bs-target. --}}
                                                <x-confirm-dialog :id="'extend-deadline-' . $opportunity->opportunity_id"
                                                                  :action="route('opportunities.extendDeadline', $opportunity->opportunity_id)"
                                                                  :title="'Extend deadline for: ' . $opportunity->title"
                                                                  confirm-label="Extend deadline"
                                                                  tone="primary">
                                                    <x-form.input name="deadline" type="date" required
                                                                  :id="'deadline-' . $opportunity->opportunity_id"
                                                                  label="New deadline"
                                                                  :min="$opportunity->deadline?->format('Y-m-d')" />

                                                    <x-form.textarea name="reason" :rows="3" required
                                                                     :id="'extend-reason-' . $opportunity->opportunity_id"
                                                                     label="Reason (for transparency, shown in the audit trail)" />
                                                </x-confirm-dialog>

                                                <x-confirm-dialog :id="'withdraw-' . $opportunity->opportunity_id"
                                                                  :action="route('opportunities.destroy', $opportunity->opportunity_id)"
                                                                  :title="'Withdraw: ' . $opportunity->title"
                                                                  confirm-label="Withdraw listing"
                                                                  method="DELETE"
                                                                  message="This removes the listing from the public site. It cannot be undone, and applicants who already applied will be notified.">
                                                    <x-form.textarea name="reason" :rows="3" required
                                                                     :id="'withdraw-reason-' . $opportunity->opportunity_id"
                                                                     label="Reason (for transparency, shown to applicants and in the audit trail)" />
                                                </x-confirm-dialog>
                                            @endunless
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">Recent applications</h2>
                    <a class="small text-decoration-none" href="{{ route('provider.applications') }}">Inbox</a>
                </div>

                @if($recentApplications->isEmpty())
                    <div class="card-body text-secondary small">No applications yet.</div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($recentApplications as $application)
                            <li class="list-group-item d-flex gap-2 align-items-center">
                                <x-avatar :user="$application->user" size="sm" />
                                <div class="min-w-0 flex-grow-1">
                                    <a class="d-block fw-semibold text-body text-decoration-none text-truncate"
                                       href="{{ route('provider.applications.show', $application->application_id) }}">
                                        {{ $application->user?->displayName() ?? 'Deleted user' }}
                                    </a>
                                    <span class="small text-secondary text-truncate d-block">
                                        {{ $application->opportunity?->title }}
                                    </span>
                                </div>
                                <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Upcoming deadlines</h2>
                </div>

                @if($upcomingDeadlines->isEmpty())
                    <div class="card-body text-secondary small">No deadlines in the next while.</div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($upcomingDeadlines as $opportunity)
                            <li class="list-group-item d-flex justify-content-between gap-2 align-items-center">
                                <span class="text-truncate">{{ $opportunity->title }}</span>
                                <x-status-badge :label="$opportunity->deadline->format('d M')"
                                                :tone="$opportunity->isClosingSoon() ? 'danger' : 'secondary'" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

@endsection
