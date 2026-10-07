@extends('layouts.app')

@section('title', 'Student reports')

@section('content')

    <x-page-header title="Student reports"
                   :subtitle="$listings->count() . ' listing(s) have reports waiting for a decision.'"
                   eyebrow="Governance" />

    {{--
        One card per reported listing. Dismissing says the reports were wrong or not
        enough; if the reports had taken the listing off the site, dismissing puts
        it back. Upholding says they were right: the listing comes down with a
        reason the provider is shown, and the provider loses trust. Both close every
        pending report on the listing at once - they are the same complaint seen
        from different students.
    --}}
    @forelse($listings as $listing)
        @php($hidden = \App\Support\OpportunityModerationStatus::isPending($listing->moderation_status)
            && in_array(\App\Services\ListingRiskChecker::REPORTED, array_column((array) $listing->risk_flags, 'code'), true))

        <div class="card mb-3" id="listing-{{ $listing->opportunity_id }}">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <div>
                    <a class="fw-semibold text-decoration-none"
                       href="{{ route('admin.moderation.show', $listing->opportunity_id) }}">{{ $listing->title }}</a>
                    <span class="small text-secondary d-block">{{ $listing->awardingBody() }}</span>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    @if($hidden)
                        <x-status-badge label="Hidden from the public" tone="danger" />
                    @else
                        <x-status-badge label="Still live" tone="warning" />
                    @endif
                    <span class="badge rounded-pill bg-secondary-subtle text-secondary">
                        {{ $listing->pending_reports_count }} {{ \Illuminate\Support\Str::plural('report', $listing->pending_reports_count) }}
                    </span>
                </div>
            </div>

            <ul class="list-group list-group-flush">
                @foreach($listing->reports as $report)
                    <li class="list-group-item">
                        <div class="fw-semibold small">{{ \App\Support\ReportReason::label($report->reason) }}</div>
                        @if($report->details)
                            <div class="small">{{ $report->details }}</div>
                        @endif
                        <div class="small text-secondary">
                            {{ $report->user?->displayName() ?? 'Deleted user' }} · {{ $report->created_at?->diffForHumans() }}
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="card-footer d-flex flex-wrap gap-2 justify-content-end">
                <form method="POST" action="{{ route('admin.listing-reports.dismiss', $listing->opportunity_id) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        {{ $hidden ? 'Dismiss and put it back' : 'Dismiss reports' }}
                    </button>
                </form>

                <x-confirm-dialog :id="'uphold-' . $listing->opportunity_id"
                                  :action="route('admin.listing-reports.uphold', $listing->opportunity_id)"
                                  :title="'Uphold reports on: ' . $listing->title"
                                  trigger-label="Uphold and take down"
                                  trigger-class="btn btn-sm btn-outline-danger"
                                  confirm-label="Uphold and take down"
                                  message="The listing comes down, the provider is told why, and they lose trust: their listings are reviewed before they go live.">
                    <x-form.textarea name="reason" :rows="3" required
                                     :id="'uphold-reason-' . $listing->opportunity_id"
                                     :bag="'uphold-' . $listing->opportunity_id"
                                     label="Reason (shown to the provider as written)" />
                </x-confirm-dialog>
            </div>
        </div>
    @empty
        <div class="card">
            <div class="card-body text-secondary">No reports are waiting. Students' reports appear here when they come in.</div>
        </div>
    @endforelse

@endsection
