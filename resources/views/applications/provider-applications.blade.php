@extends('layouts.app')

@section('title', 'Applications')

@section('content')

    <x-page-header title="Applications"
                   :subtitle="number_format($applications->total()) . ' application(s) across your listings.'"
                   eyebrow="Provider" />

    <ul class="nav nav-pills gap-2 mb-4 flex-nowrap overflow-auto pb-2">
        <li class="nav-item">
            <a class="nav-link @active(!$activeStatus)" href="{{ route('provider.applications') }}">
                All <span class="badge bg-body-secondary text-body ms-1">{{ array_sum($statusCounts) }}</span>
            </a>
        </li>
        @foreach($statuses as $status)
            <li class="nav-item">
                <a class="nav-link text-nowrap @active($activeStatus === $status)"
                   href="{{ route('provider.applications', ['status' => $status]) }}">
                    {{ \App\Support\ApplicationStatus::displayLabel($status) }}
                    <span class="badge bg-body-secondary text-body ms-1">{{ $statusCounts[$status] ?? 0 }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    @if($applications->isEmpty())
        <div class="card">
            <x-empty-state title="No applications here"
                           message="Once students apply to your listings, they appear in this inbox."
                           icon="inbox"
                           action-label="Post a scholarship"
                           :action-href="route('opportunities.create')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Applicant</th>
                            <th scope="col">Scholarship</th>
                            <th scope="col">Submitted</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($applications as $application)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <x-avatar :user="$application->user" size="sm" />
                                        <div class="min-w-0">
                                            <span class="fw-semibold d-block text-truncate">
                                                {{ $application->user?->displayName() ?? 'Deleted user' }}
                                            </span>
                                            <span class="small text-secondary">
                                                {{ $application->user?->applicantProfile?->education_level ?? 'Level not set' }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-secondary">{{ $application->opportunity?->title }}</td>
                                <td class="text-secondary small">{{ $application->submitted_at?->format('d M Y') }}</td>
                                <td>
                                    <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-primary"
                                       href="{{ route('provider.applications.show', $application->application_id) }}">Review</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $applications->links() }}
        </div>
    @endif

@endsection
