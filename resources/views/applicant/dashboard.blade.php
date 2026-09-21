@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

    <x-page-header :title="$greeting" subtitle="Here is where your scholarship search stands today.">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('applicant.recommendations') }}">My matches</a>
            <a class="btn btn-primary" href="{{ route('opportunities.index') }}">Find scholarships</a>
        </x-slot:actions>
    </x-page-header>

    {{-- Only while it is actionable, and in the same shape the profile and the
         matches page use it. --}}
    @if($stats['profileCompletion'] < 100)
        <x-profile-progress :profile="$profile" class="mb-4" />
    @endif

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-4 col-xl">
            <x-stat-card label="Applications" :value="$stats['applications']" icon="file-text" tone="primary"
                         :href="route('applications.mine')" />
        </div>
        <div class="col-6 col-lg-4 col-xl">
            <x-stat-card label="In progress" :value="$stats['inProgress']" icon="hourglass-split" tone="warning" />
        </div>
        <div class="col-6 col-lg-4 col-xl">
            <x-stat-card label="Accepted" :value="$stats['accepted']" icon="check-circle" tone="success" />
        </div>
        <div class="col-6 col-lg-4 col-xl">
            <x-stat-card label="Rejected" :value="$stats['rejected'] ?? 0" icon="x-circle" tone="danger" />
        </div>
        <div class="col-6 col-lg-4 col-xl">
            <x-stat-card label="Saved" :value="$stats['saved']" icon="bookmark" tone="info"
                         :href="route('applicant.saved')" />
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">

            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">Top matches for you</h2>
                    <a class="small text-decoration-none" href="{{ route('applicant.recommendations') }}">View all</a>
                </div>

                <div class="card-body">
                    @if(empty($recommendations))
                        <x-empty-state title="No matches yet"
                                       message="Complete your profile so ScholarFit can score open scholarships against it."
                                       icon="stars"
                                       action-label="Complete my profile"
                                       :action-href="route('applicant.profile')" />
                    @else
                        <div class="row g-3">
                            @foreach($recommendations as $match)
                                <div class="col-md-6">
                                    <x-scholarship-card :opportunity="$match->opportunity" :score="$match->matchScore"
                                                        :saved="in_array($match->opportunity->opportunity_id, $savedIds, true)"
                                                        :applied="in_array($match->opportunity->opportunity_id, $appliedIds, true)"
                                                        :accepted="$accepted[$match->opportunity->opportunity_id] ?? null" />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">Recent applications</h2>
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('applications.mine') }}">View all applications</a>
                </div>

                @if($recentApplications->isEmpty())
                    <x-empty-state title="You have not applied yet"
                                   message="When you apply, every status change shows up here."
                                   icon="file-text"
                                   action-label="Find a scholarship"
                                   :action-href="route('opportunities.index')" />
                @else
                    <x-data-table :columns="['Scholarship', 'Submitted', 'Status', ['label' => 'Action', 'align' => 'end']]">
                        @foreach($recentApplications as $application)
                            <tr>
                                <x-data-table.cell label="Scholarship" class="fw-semibold">{{ $application->opportunity?->title ?? 'Removed listing' }}</x-data-table.cell>
                                <x-data-table.cell label="Submitted" class="text-secondary small">{{ $application->submitted_at?->format('d M Y') }}</x-data-table.cell>
                                <x-data-table.cell label="Status">
                                    <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />
                                </x-data-table.cell>
                                <x-data-table.cell align="end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('applications.confirmation', $application->application_id) }}">View</a>
                                </x-data-table.cell>
                            </tr>
                        @endforeach
                    </x-data-table>
                @endif
            </div>
        </div>

        <div class="col-xl-4">

            <div class="card mb-4">
                <div class="card-body text-center">
                    <x-match-score :score="$stats['topMatch']" label="Best match" size="lg" />
                    <p class="small text-secondary mt-3 mb-0">
                        Your strongest ScholarFit score across all open listings.
                    </p>
                </div>
            </div>

            {{-- The same checklist the profile shows, from the same component. It used
                 to be a dial plus a bare list of gaps here and an annotated checklist
                 there, which meant two answers to one question. --}}
            <x-profile-progress :profile="$profile" variant="checklist" heading="Profile strength" class="mb-4" />

            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Upcoming deadlines</h2>
                </div>

                @if($upcomingDeadlines->isEmpty())
                    <div class="card-body text-secondary small">
                        Nothing on your watchlist. Save a scholarship and its deadline shows up here.
                    </div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($upcomingDeadlines as $opportunity)
                            <li class="list-group-item d-flex gap-2 align-items-center">
                                <div class="min-w-0 flex-grow-1">
                                    <a class="d-block text-body text-decoration-none fw-semibold text-truncate"
                                       href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">
                                        {{ $opportunity->title }}
                                    </a>
                                    <span class="small text-secondary">{{ $opportunity->deadline->format('d M Y') }}</span>
                                </div>
                                <x-status-badge :label="$opportunity->daysUntilDeadline() . 'd'"
                                                :tone="$opportunity->isClosingSoon() ? 'danger' : 'secondary'" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

@endsection
