@extends('layouts.app')

@section('title', 'Admin dashboard')

@section('content')

    <x-page-header :title="$greeting" subtitle="Platform health, review queues, and recent activity.">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('admin.analytics') }}">Analytics</a>
            <a class="btn btn-primary" href="{{ route('admin.users.create') }}">Create user</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-stat-card label="Users" :value="number_format($stats['totalUsers'])" icon="people" tone="primary"
                         :hint="$stats['applicants'] . ' students, ' . $stats['providers'] . ' providers'"
                         :href="route('admin.users.index')" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Live listings" :value="number_format($stats['activeOpportunities'])"
                         icon="stars" tone="success"
                         :hint="$stats['totalOpportunities'] . ' total'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Applications" :value="number_format($stats['totalApplications'])"
                         icon="file-text" tone="info"
                         :hint="$stats['pendingApplications'] . ' awaiting a decision'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Awards made" :value="number_format($stats['approvedApplications'])"
                         icon="check-circle" tone="success" />
        </div>
    </div>

    <div class="card mb-4" id="scholarship-moderation">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h6 fw-semibold mb-0">
                Scholarships awaiting review
                @if($moderationQueue->isNotEmpty())
                    <span class="badge bg-warning-subtle text-warning ms-1">{{ $moderationQueue->count() }}</span>
                @endif
            </h2>
        </div>

        @if($moderationQueue->isEmpty())
            <div class="card-body text-secondary small">
                Nothing in the queue. New listings appear here the moment a provider submits them.
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Scholarship</th>
                            <th scope="col">Provider</th>
                            <th scope="col">Submitted</th>
                            <th scope="col" class="text-end">Decision</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($moderationQueue as $opportunity)
                            <tr>
                                <td>
                                    <span class="fw-semibold d-block">{{ $opportunity->title }}</span>
                                    <span class="small text-secondary">
                                        {{ $opportunity->education_level ?: 'Any level' }} &middot;
                                        {{ $opportunity->target_field ?: 'Any field' }} &middot;
                                        closes {{ $opportunity->deadline?->format('d M Y') ?? 'rolling' }}
                                    </span>
                                </td>
                                <td class="text-secondary">{{ $opportunity->awardingBody() }}</td>
                                <td class="text-secondary small">{{ $opportunity->submitted_at?->diffForHumans() }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-2">
                                        <form method="POST"
                                              action="{{ route('admin.moderation.approve', $opportunity->opportunity_id) }}"
                                              class="m-0">
                                            @csrf
                                            <button class="btn btn-sm btn-success" type="submit">Approve</button>
                                        </form>

                                        <button class="btn btn-sm btn-outline-danger" type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#decline-{{ $opportunity->opportunity_id }}">
                                            Decline
                                        </button>
                                    </div>

                                    <div class="modal fade text-start" id="decline-{{ $opportunity->opportunity_id }}"
                                         tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <form method="POST"
                                                  action="{{ route('admin.moderation.reject', $opportunity->opportunity_id) }}"
                                                  class="modal-content">
                                                @csrf
                                                <div class="modal-header">
                                                    <h3 class="modal-title h6">Decline "{{ $opportunity->title }}"</h3>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                            aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <label class="form-label"
                                                           for="reason-{{ $opportunity->opportunity_id }}">
                                                        Reason (sent to the provider)
                                                    </label>
                                                    <textarea class="form-control" rows="4" required
                                                              id="reason-{{ $opportunity->opportunity_id }}"
                                                              name="reason"></textarea>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-outline-secondary"
                                                            data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger">Decline listing</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card" id="provider-verification">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">
                        Providers awaiting verification
                        @if($pendingProviders->isNotEmpty())
                            <span class="badge bg-warning-subtle text-warning ms-1">{{ $pendingProviders->count() }}</span>
                        @endif
                    </h2>
                    <a class="small text-decoration-none" href="{{ route('admin.users.index') }}">All users</a>
                </div>

                @if($pendingProviders->isEmpty())
                    <div class="card-body text-secondary small">No organisations are waiting on verification.</div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($pendingProviders as $profile)
                            <li class="list-group-item">
                                <div class="d-flex flex-wrap gap-2 align-items-start justify-content-between">
                                    <div class="min-w-0">
                                        <span class="fw-semibold d-block">{{ $profile->user?->displayName() }}</span>
                                        <span class="small text-secondary d-block">
                                            {{ $profile->organisationTypeLabel() }} &middot;
                                            Reg. {{ $profile->registration_number }}
                                        </span>
                                        <a class="small text-decoration-none d-inline-flex align-items-center gap-1 mt-1"
                                           href="{{ route('admin.providers.certificate', $profile->user_id) }}">
                                            <x-icon name="download" :size="14" />{{ $profile->certificate_filename }}
                                        </a>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <form method="POST"
                                              action="{{ route('admin.providers.approve', $profile->user_id) }}"
                                              class="m-0">
                                            @csrf
                                            <button class="btn btn-sm btn-success" type="submit">Approve</button>
                                        </form>

                                        <button class="btn btn-sm btn-outline-danger" type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#reject-provider-{{ $profile->user_id }}">
                                            Reject
                                        </button>
                                    </div>
                                </div>

                                <div class="modal fade" id="reject-provider-{{ $profile->user_id }}"
                                     tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <form method="POST"
                                              action="{{ route('admin.providers.reject', $profile->user_id) }}"
                                              class="modal-content">
                                            @csrf
                                            <div class="modal-header">
                                                <h3 class="modal-title h6">Reject {{ $profile->user?->displayName() }}</h3>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                        aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <label class="form-label" for="provider-reason-{{ $profile->user_id }}">
                                                    Reason (sent to the organisation)
                                                </label>
                                                <textarea class="form-control" rows="4" required
                                                          id="provider-reason-{{ $profile->user_id }}"
                                                          name="reason"></textarea>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary"
                                                        data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger">Reject provider</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">Recent activity</h2>
                    <a class="small text-decoration-none" href="{{ route('admin.audit') }}">Full audit log</a>
                </div>

                @if($recentActivity->isEmpty())
                    <div class="card-body text-secondary small">Nothing recorded yet.</div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($recentActivity as $entry)
                            <li class="list-group-item d-flex gap-2 align-items-start">
                                <x-status-badge :label="\App\Support\AuditAction::displayLabel($entry->action)"
                                                :tone="\App\Support\AuditAction::badgeTone($entry->action)" />
                                <div class="min-w-0 flex-grow-1">
                                    <span class="small d-block text-truncate">{{ $entry->details ?: $entry->entity_type }}</span>
                                    <span class="small text-secondary">
                                        {{ $entry->actor_email }} &middot; {{ $entry->created_at?->diffForHumans() }}
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

@endsection
