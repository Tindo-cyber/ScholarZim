@extends('layouts.app')

@section('title', 'My applications')

@section('content')

    <x-page-header title="My applications"
                   :subtitle="number_format($applications->total()) . ' application(s) submitted.'">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('applicant.recommendations') }}">Find more matches</a>
        </x-slot:actions>
    </x-page-header>

    <ul class="nav nav-pills gap-2 mb-4 flex-nowrap overflow-auto pb-2">
        <li class="nav-item">
            <a class="nav-link @active(!$activeStatus)" href="{{ route('applications.mine') }}">
                All <span class="badge bg-body-secondary text-body ms-1">{{ array_sum($statusCounts) }}</span>
            </a>
        </li>
        @foreach($statuses as $status)
            <li class="nav-item">
                <a class="nav-link text-nowrap @active($activeStatus === $status)"
                   href="{{ route('applications.mine', ['status' => $status]) }}">
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
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Scholarship</th>
                            <th scope="col">Awarding body</th>
                            <th scope="col">Submitted</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($applications as $application)
                            <tr>
                                <td>
                                    <span class="fw-semibold d-block">{{ $application->opportunity?->title ?? 'Removed listing' }}</span>
                                    @if($application->opportunity?->deadline)
                                        <span class="small text-secondary">
                                            Closes {{ $application->opportunity->deadline->format('d M Y') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-secondary">{{ $application->opportunity?->awardingBody() }}</td>
                                <td class="text-secondary small">{{ $application->submitted_at?->format('d M Y') }}</td>
                                <td>
                                    <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />
                                    @if($application->application_status === \App\Support\ApplicationStatus::INTERVIEW && $application->interview_at)
                                        <span class="d-block small text-secondary mt-1">
                                            {{ $application->interview_at->format('d M Y \a\t g:i A') }}
                                        </span>
                                    @elseif($application->rejection_reason)
                                        <span class="d-block small text-secondary mt-1">{{ $application->rejection_reason }}</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('applications.confirmation', $application->application_id) }}">View</a>
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
