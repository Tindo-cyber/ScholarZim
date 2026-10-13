@extends('layouts.app')

@section('title', 'My reports')

@section('content')

    <x-page-header title="My reports"
                   subtitle="Scholarships you have reported to an administrator, and what became of each report."
                   eyebrow="Student">
        <x-slot:actions>
            <a class="btn btn-outline-primary" href="{{ route('opportunities.index') }}">Find scholarships</a>
        </x-slot:actions>
    </x-page-header>

    {{--
        Where a report is made, said every time: the form is on the scholarship itself, because that is
        where a student notices something wrong. This page is where they come back to see what happened.
    --}}
    <div class="alert alert-info small" role="note">
        <strong>How to report a scholarship.</strong> Open it from <a href="{{ route('opportunities.index') }}">Find scholarships</a>
        and choose <strong>Report this listing</strong>, for example if it asks you to pay money, looks fake, or has
        the wrong deadline. An administrator reads every report.
    </div>

    @if($reports->isEmpty())
        <div class="card">
            <x-empty-state title="You have not reported anything"
                           message="If a scholarship looks wrong, open it and choose Report this listing. Your reports will be listed here."
                           icon="shield"
                           action-label="Find scholarships"
                           :action-href="route('opportunities.index')" />
        </div>
    @else
        <div class="card">
            <x-data-table :columns="['Scholarship', 'What you said', 'Reported', 'Status']">
                @foreach($reports as $report)
                    @php
                        $listing = $report->opportunity;
                        $title = $listing?->title ?? 'Removed listing';
                        // A link only to a listing a student can still open; a taken-down one would be a 404.
                        $open = $listing && \App\Models\Opportunity::query()->publiclyVisible()->whereKey($listing->opportunity_id)->exists();
                        [$statusLabel, $statusTone, $statusNote] = match ($report->status) {
                            \App\Support\ReportStatus::UPHELD => ['The administrator agreed', 'success', 'Thank you. The listing was dealt with.'],
                            \App\Support\ReportStatus::DISMISSED => ['No action was needed', 'secondary', 'An administrator looked at it and the listing stays as it is.'],
                            default => ['Waiting for an administrator', 'warning', 'It has not been looked at yet.'],
                        };
                    @endphp
                    <tr>
                        <x-data-table.cell label="Scholarship" class="fw-semibold">
                            @if($open)
                                <a href="{{ route('scholarships.show', $listing->opportunity_id) }}">{{ $title }}</a>
                            @else
                                {{ $title }}
                            @endif
                        </x-data-table.cell>

                        <x-data-table.cell label="What you said">
                            {{ \App\Support\ReportReason::label($report->reason) }}
                            @if($report->details)
                                <span class="d-block small text-secondary mt-1">{{ $report->details }}</span>
                            @endif
                        </x-data-table.cell>

                        <x-data-table.cell label="Reported" class="text-secondary small">
                            {{ $report->created_at?->format('d M Y') }}
                        </x-data-table.cell>

                        <x-data-table.cell label="Status">
                            <x-status-badge :label="$statusLabel" :tone="$statusTone" />
                            <span class="d-block small text-secondary mt-1">{{ $statusNote }}</span>
                        </x-data-table.cell>
                    </tr>
                @endforeach
            </x-data-table>
        </div>

        <div class="mt-4">
            {{ $reports->links() }}
        </div>
    @endif

@endsection
