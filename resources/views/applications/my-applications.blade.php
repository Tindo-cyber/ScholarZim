@extends('layouts.app')

@section('title', 'My applications')

@section('content')

    <x-page-header title="My applications"
                   subtitle="Track the progress and status of your scholarship applications.">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('applicant.recommendations') }}">Find more matches</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-sm-3">
            <x-stat-card label="Total" :value="array_sum($statusCounts)" icon="file-text" tone="primary" />
        </div>
        <div class="col-6 col-sm-3">
            <x-stat-card label="Pending" :value="$statusCounts[\App\Support\ApplicationStatus::PENDING] ?? 0"
                         icon="hourglass-split" tone="warning" />
        </div>
        <div class="col-6 col-sm-3">
            <x-stat-card label="Accepted" :value="$statusCounts[\App\Support\ApplicationStatus::ACCEPTED] ?? 0"
                         icon="check-circle" tone="success" />
        </div>
        <div class="col-6 col-sm-3">
            <x-stat-card label="Rejected" :value="$statusCounts[\App\Support\ApplicationStatus::REJECTED] ?? 0"
                         icon="x-circle" tone="danger" />
        </div>
    </div>

    <form method="GET" action="{{ route('applications.mine') }}" class="d-flex gap-2 mb-4">
        @if($activeStatus)
            <input type="hidden" name="status" value="{{ $activeStatus }}" />
        @endif
        <div class="flex-grow-1">
            <input class="form-control" type="search" name="search" value="{{ $search ?? '' }}"
                   placeholder="Search by scholarship, provider, or application number" />
        </div>
        <button class="btn btn-outline-secondary" type="submit">Search</button>
        @if($search)
            <a class="btn btn-outline-secondary" href="{{ route('applications.mine', $activeStatus ? ['status' => $activeStatus] : []) }}">Clear</a>
        @endif
    </form>

    <ul class="nav nav-pills gap-2 mb-4 flex-nowrap overflow-auto pb-2">
        <li class="nav-item">
            <a class="nav-link @active(!$activeStatus)"
               href="{{ route('applications.mine', $search ? ['search' => $search] : []) }}">
                All <span class="badge bg-body-secondary text-body ms-1">{{ array_sum($statusCounts) }}</span>
            </a>
        </li>
        @foreach($statuses as $status)
            <li class="nav-item">
                <a class="nav-link text-nowrap @active($activeStatus === $status)"
                   href="{{ route('applications.mine', array_filter(['status' => $status, 'search' => $search])) }}">
                    {{ \App\Support\ApplicationStatus::displayLabel($status) }}
                    <span class="badge bg-body-secondary text-body ms-1">{{ $statusCounts[$status] ?? 0 }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    @if($applications->isEmpty())
        <div class="card">
            <x-empty-state title="Nothing here"
                           message="You have not submitted an application under this filter yet."
                           icon="file-text"
                           action-label="Browse scholarships"
                           :action-href="route('opportunities.index')" />
        </div>
    @else
        <div class="card">
            <x-data-table :columns="[
                'Scholarship',
                'Awarding body',
                'Application #',
                'Submitted',
                'Status',
                ['label' => 'Action', 'align' => 'end'],
            ]">
                @foreach($applications as $application)
                    <tr>
                        <x-data-table.cell label="Scholarship">
                            <span class="fw-semibold d-block">{{ $application->opportunity?->title ?? 'Removed listing' }}</span>
                            @if($application->opportunity?->deadline)
                                <span class="small text-secondary">
                                    Closes {{ $application->opportunity->deadline->format('d M Y') }}
                                </span>
                            @endif
                        </x-data-table.cell>

                        <x-data-table.cell label="Awarding body" class="text-secondary">
                            {{ $application->opportunity?->awardingBody() }}
                        </x-data-table.cell>

                        <x-data-table.cell label="Application #" class="text-secondary small sz-tabular">
                            #{{ $application->application_id }}
                        </x-data-table.cell>

                        <x-data-table.cell label="Submitted" class="text-secondary small">
                            {{ $application->submitted_at?->format('d M Y') }}
                        </x-data-table.cell>

                        <x-data-table.cell label="Status">
                            <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />

                            {{--
                                The provider's reason is the substance of a decision, so
                                it is shown here rather than only on the detail page.
                            --}}
                            @if($application->isAccepted())
                                <span class="d-block small text-success fw-semibold mt-1">
                                    Accepted {{ $application->decided_at?->format('d M Y') }}
                                </span>
                            @elseif($application->isWithdrawn() && $application->withdrawn_at)
                                <span class="d-block small text-secondary mt-1">
                                    Withdrawn {{ $application->withdrawn_at->format('d M Y') }}
                                </span>
                            @endif

                            @if($application->isDecided() && $application->decision_reason)
                                <span class="d-block small text-secondary mt-1">{{ $application->decision_reason }}</span>
                            @endif
                        </x-data-table.cell>

                        <x-data-table.cell align="end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('applications.confirmation', $application->application_id) }}">View details</a>
                        </x-data-table.cell>
                    </tr>
                @endforeach
            </x-data-table>
        </div>

        <div class="mt-4">
            {{ $applications->links() }}
        </div>
    @endif

@endsection
