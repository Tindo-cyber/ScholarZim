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
        {{--
            One form wraps the table so a selection made in the rows is submitted by
            the bar below it. The per-row Review link sits outside the submit path,
            so the two never interfere.
        --}}
        <form method="POST" action="{{ route('provider.applications.bulk') }}" id="bulk-review-form">
            @csrf

            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 sz-table-stack" data-bulk-table>
                        <thead>
                            <tr>
                                <th scope="col" style="width: 2.5rem;">
                                    <input class="form-check-input" type="checkbox"
                                           data-bulk-toggle-all aria-label="Select every application on this page">
                                </th>
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
                                    <td data-label="">
                                        <input class="form-check-input" type="checkbox"
                                               name="applications[]" value="{{ $application->application_id }}"
                                               data-bulk-item
                                               @disabled($application->isWithdrawn())
                                               aria-label="Select the application from {{ $application->user?->displayName() ?? 'a deleted user' }}">
                                    </td>
                                    <td data-label="Applicant">
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
                                    </td>
                                    <td data-label="Scholarship" class="text-secondary">{{ $application->opportunity?->title }}</td>
                                    <td data-label="Submitted" class="text-secondary small">{{ $application->submitted_at?->format('d M Y') }}</td>
                                    <td data-label="Status">
                                        <span class="min-w-0">
                                            <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />

                                            @if($application->info_responded_at && ! $application->awaitsApplicantResponse())
                                                <span class="d-block small text-primary fw-semibold mt-1">
                                                    Answered your question
                                                </span>
                                            @elseif($application->awaitsApplicantResponse())
                                                <span class="d-block small text-secondary mt-1">Waiting on the applicant</span>
                                            @endif
                                        </span>
                                    </td>
                                    <td data-label="" class="text-end">
                                        <a class="btn btn-sm btn-primary"
                                           href="{{ route('provider.applications.show', $application->application_id) }}">Review</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="card-footer">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-3">
                            <label class="form-label small" for="bulk-status">Set selected to</label>
                            <select class="form-select form-select-sm" id="bulk-status" name="status">
                                @foreach($bulkStatuses as $status)
                                    <option value="{{ $status }}">
                                        {{ \App\Support\ApplicationStatus::displayLabel($status) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label small" for="bulk-reason">
                                Message to the applicants
                            </label>
                            <input type="text" class="form-control form-control-sm" id="bulk-reason"
                                   name="reason" maxlength="500"
                                   placeholder="Required for approve, decline, and any request for information">
                        </div>
                        <div class="col-12 col-lg-3 d-grid">
                            <button class="btn btn-sm btn-primary" type="submit">
                                Apply to <span data-bulk-count>0</span> selected
                            </button>
                        </div>
                    </div>

                    <p class="form-text mb-0 mt-2">
                        Every application in a batch goes through the same checks as a single decision, and each
                        applicant is notified individually. Interviews are scheduled one at a time, since each
                        needs its own date.
                    </p>
                </div>
            </div>
        </form>

        <div class="mt-4">
            {{ $applications->links() }}
        </div>
    @endif

@endsection
