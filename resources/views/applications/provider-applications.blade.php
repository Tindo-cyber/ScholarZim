@extends('layouts.app')

@section('title', 'Applications')

@section('content')

    <x-page-header title="Applications"
                   :subtitle="number_format($applications->total()) . ' application(s) across your listings.'"
                   eyebrow="Provider">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('provider.analytics') }}">
                <x-icon name="trend" :size="16" /> Analytics
            </a>
        </x-slot:actions>
    </x-page-header>

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
            <x-data-table :columns="['Applicant', 'Scholarship', 'Submitted', 'Status', ['label' => 'Action', 'align' => 'end']]">
                        @foreach($applications as $application)
                            <tr>
                                <x-data-table.cell label="Applicant">
                                    <span class="d-flex align-items-center gap-2">
                                        <x-avatar :user="$application->user" size="sm" />
                                        <span class="min-w-0">
                                            <span class="fw-semibold d-block text-truncate">
                                                {{ $application->user?->displayName() ?? 'Deleted user' }}
                                            </span>
                                            <span class="small text-secondary">
                                                {{ $application->user?->applicantProfile?->education_level ?? 'Level not set' }}
                                            </span>
                                        </span>
                                    </span>
                                </x-data-table.cell>
                                <x-data-table.cell label="Scholarship" class="text-secondary">{{ $application->opportunity?->title }}</x-data-table.cell>
                                <x-data-table.cell label="Submitted" class="text-secondary small">{{ $application->submitted_at?->format('d M Y') }}</x-data-table.cell>
                                <x-data-table.cell label="Status">
                                    <span class="min-w-0">
                                        <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />

                                        @if($application->isDecided())
                                            <span class="d-block small text-secondary mt-1">
                                                Decided {{ $application->decided_at?->format('d M Y') ?? 'date not recorded' }}
                                            </span>
                                        @endif
                                    </span>
                                </x-data-table.cell>
                                <x-data-table.cell align="end">
                                    <a class="btn btn-sm btn-primary"
                                       href="{{ route('provider.applications.show', $application->application_id) }}">Review</a>
                                </x-data-table.cell>
                            </tr>
                        @endforeach
            </x-data-table>

            <div class="card-footer">
                <p class="form-text mb-0">
                    Open an application to review it. Decisions are made one at a time, each with a written
                    reason the applicant reads verbatim.
                </p>
            </div>
        </div>

        <div class="mt-4">
            {{ $applications->links() }}
        </div>
    @endif

@endsection
