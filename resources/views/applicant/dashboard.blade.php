@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

    <x-page-header :title="$greeting" subtitle="Here is where your scholarship search stands today.">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('opportunities.index') }}">Browse scholarships</a>
            <a class="btn btn-primary" href="{{ route('applicant.recommendations') }}">See my matches</a>
        </x-slot:actions>
    </x-page-header>

    @if($stats['profileCompletion'] < 100)
        <div class="alert alert-info d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div>
                <strong>Your profile is {{ $stats['profileCompletion'] }}% complete.</strong>
                <span class="d-block small">
                    ScholarFit can only score what it knows about you - finish your profile for accurate matches.
                </span>
            </div>
            <a class="btn btn-sm btn-info" href="{{ route('applicant.profile') }}">Complete profile</a>
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-stat-card label="Applications" :value="$stats['applications']" icon="file-text" tone="primary"
                         :href="route('applications.mine')" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="In progress" :value="$stats['inProgress']" icon="hourglass-split" tone="warning" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Accepted" :value="$stats['accepted']" icon="check-circle" tone="success" />
        </div>
        <div class="col-6 col-xl-3">
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
                    <a class="small text-decoration-none" href="{{ route('applications.mine') }}">View all</a>
                </div>

                @if($recentApplications->isEmpty())
                    <x-empty-state title="You have not applied yet"
                                   message="When you apply, every status change shows up here."
                                   icon="file-text"
                                   action-label="Find a scholarship"
                                   :action-href="route('opportunities.index')" />
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Scholarship</th>
                                    <th scope="col">Submitted</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentApplications as $application)
                                    <tr>
                                        <td class="fw-semibold">{{ $application->opportunity?->title ?? 'Removed listing' }}</td>
                                        <td class="text-secondary small">{{ $application->submitted_at?->format('d M Y') }}</td>
                                        <td>
                                            <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />
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

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Profile strength</h2>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <x-match-score :score="$stats['profileCompletion']"
                                       size="lg"
                                       :label="$stats['profileCompletion'] >= 100 ? 'Complete' : 'Completion'" />
                    </div>

                    @if($profile->missingFields())
                        <p class="small text-secondary mb-2">Still missing:</p>
                        {{--
                            Each gap links straight at the field that closes it, so
                            the list is a set of actions rather than a scorecard.
                        --}}
                        <ul class="list-unstyled d-grid gap-1 small mb-3">
                            @foreach($profile->completionChecklist() as $item)
                                @continue($item['done'])
                                <li class="d-flex align-items-center gap-2">
                                    <x-icon name="x-circle" :size="14" class="text-secondary" />
                                    <a href="{{ route('applicant.profile') }}#{{ $item['anchor'] === 'documents' ? 'documents' : 'field-' . $item['anchor'] }}">
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="small text-success mb-3 d-flex align-items-center gap-2">
                            <x-icon name="check-circle" :size="14" />Your profile is complete.
                        </p>
                    @endif

                    <a class="btn btn-sm btn-outline-primary w-100" href="{{ route('applicant.profile') }}">Edit profile</a>
                </div>
            </div>

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
