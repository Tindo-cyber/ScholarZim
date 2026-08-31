@extends('layouts.app')

@section('title', 'Analytics')

@php
    // Inline SVG rather than a charting library, matching the admin analytics page:
    // the shell stays dependency-free and the chart still scales and re-themes.
    $trend = $overview['viewTrend'];
    $peak = max(1, collect($trend)->max('views'));
    $step = count($trend) > 1 ? 100 / (count($trend) - 1) : 100;

    $points = collect($trend)->map(function ($day, $index) use ($peak, $step) {
        return round($index * $step, 2) . ',' . round(40 - ($day['views'] / $peak * 36), 2);
    })->implode(' ');
@endphp

@section('content')

    <x-page-header title="Analytics"
                   subtitle="How your listings are performing, from first view to award."
                   eyebrow="Provider">
        <x-slot:actions>
            <div class="btn-group" role="group" aria-label="Reporting period">
                @foreach($ranges as $range)
                    <a class="btn btn-sm {{ $days === $range ? 'btn-primary' : 'btn-outline-secondary' }}"
                       href="{{ route('provider.analytics', ['days' => $range]) }}">{{ $range }} days</a>
                @endforeach
            </div>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        {{-- col-xl rather than col-xl-3: the funnel has five stages since
             approval and the award became separate facts. --}}
        @foreach($overview['funnel'] as $stage)
            <div class="col-6 col-xl">
                <x-stat-card :label="$stage['label']"
                             :value="number_format($stage['value'])"
                             :icon="match($stage['label']) {
                                 'Views' => 'eye',
                                 'Saves' => 'bookmark',
                                 'Applications' => 'inbox',
                                 'Awarded' => 'stars',
                                 default => 'check-circle',
                             }"
                             :tone="match($stage['label']) {
                                 'Views' => 'info',
                                 'Saves' => 'primary',
                                 'Applications' => 'warning',
                                 default => 'success',
                             }"
                             :progress="$stage['share']"
                             :hint="$stage['stepRate'] === null
                                 ? 'Everyone who opened a listing'
                                 : $stage['stepRate'] . '% of the previous step'" />
            </div>
        @endforeach
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <h2 class="h6 fw-semibold mb-0">Views over the last {{ $days }} days</h2>
                    <span class="small text-secondary">
                        Peak {{ number_format($peak) }} in a day
                    </span>
                </div>
                <div class="card-body">
                    @if(collect($trend)->sum('views') === 0)
                        <p class="text-secondary mb-0">
                            No views recorded yet in this period. Views are counted when a student opens one of
                            your listings; your own visits are not counted.
                        </p>
                    @else
                        <svg viewBox="0 0 100 40" preserveAspectRatio="none" role="img"
                             class="w-100" style="height: 10rem;"
                             aria-label="Daily views over the last {{ $days }} days, peaking at {{ $peak }}">
                            <polyline points="{{ $points }}" fill="none" stroke="currentColor"
                                      stroke-width="1" class="text-primary"
                                      vector-effect="non-scaling-stroke" />
                        </svg>

                        <div class="d-flex justify-content-between small text-secondary mt-2">
                            <span>{{ \Illuminate\Support\Carbon::parse($trend[0]['date'])->format('d M') }}</span>
                            <span>{{ \Illuminate\Support\Carbon::parse(end($trend)['date'])->format('d M') }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">By listing</h2>
                </div>

                @if($overview['byListing'] === [])
                    <x-empty-state title="No listings yet"
                                   message="Post a scholarship and its numbers appear here."
                                   icon="inbox"
                                   action-label="Post a scholarship"
                                   :action-href="route('opportunities.create')" />
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 sz-table-stack">
                            <thead>
                                <tr>
                                    <th scope="col">Listing</th>
                                    <th scope="col" class="text-end">Views</th>
                                    <th scope="col" class="text-end">Saves</th>
                                    <th scope="col" class="text-end">Applications</th>
                                    <th scope="col" class="text-end">Approved</th>
                                    <th scope="col" class="text-end">Awarded</th>
                                    <th scope="col" class="text-end">Conversion</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($overview['byListing'] as $row)
                                    <tr>
                                        <td data-label="Listing">
                                            <span class="min-w-0">
                                                <a class="fw-semibold text-decoration-none d-block"
                                                   href="{{ route('scholarships.show', $row['opportunity']->opportunity_id) }}">
                                                    {{ $row['opportunity']->title }}
                                                </a>
                                                <span class="small text-secondary">
                                                    {{ $row['opportunity']->lifecycleLabel() }}
                                                </span>
                                            </span>
                                        </td>
                                        <td data-label="Views" class="text-end">{{ number_format($row['views']) }}</td>
                                        <td data-label="Saves" class="text-end">{{ number_format($row['saves']) }}</td>
                                        <td data-label="Applications" class="text-end">{{ number_format($row['applications']) }}</td>
                                        <td data-label="Approved" class="text-end">{{ number_format($row['approved']) }}</td>
                                        <td data-label="Awarded" class="text-end">{{ number_format($row['awarded']) }}</td>
                                        <td data-label="Conversion" class="text-end">
                                            {{ $row['views'] > 0
                                                ? round($row['applications'] / $row['views'] * 100, 1) . '%'
                                                : '—' }}
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
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Headline rates</h2>
                </div>
                <div class="card-body d-grid gap-3">
                    <div>
                        <div class="d-flex justify-content-between">
                            <span class="small text-secondary">View to application</span>
                            <span class="fw-bold">{{ $overview['conversionRate'] }}%</span>
                        </div>
                        <div class="progress mt-1" style="height:.375rem;">
                            <div class="progress-bar bg-primary"
                                 style="width: {{ min(100, $overview['conversionRate']) }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between">
                            <span class="small text-secondary">Application to award</span>
                            <span class="fw-bold">{{ $overview['awardRate'] }}%</span>
                        </div>
                        <div class="progress mt-1" style="height:.375rem;">
                            <div class="progress-bar bg-success"
                                 style="width: {{ min(100, $overview['awardRate']) }}%"></div>
                        </div>
                    </div>
                </div>
            </div>

            @foreach([
                ['Applicants by field', $overview['fieldBreakdown']],
                ['Applicants by level', $overview['levelBreakdown']],
            ] as [$heading, $rows])
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">{{ $heading }}</h2>
                    </div>
                    <div class="card-body">
                        @if($rows === [])
                            <p class="small text-secondary mb-0">No applications yet.</p>
                        @else
                            @php $topCount = max(1, collect($rows)->max('total')); @endphp
                            <ul class="list-unstyled d-grid gap-2 mb-0 small">
                                @foreach($rows as $row)
                                    <li>
                                        <div class="d-flex justify-content-between gap-2">
                                            <span class="text-truncate">{{ $row['label'] }}</span>
                                            <span class="text-secondary flex-shrink-0">{{ $row['total'] }}</span>
                                        </div>
                                        <div class="progress mt-1" style="height:.25rem;">
                                            <div class="progress-bar bg-info"
                                                 style="width: {{ round($row['total'] / $topCount * 100) }}%"></div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endforeach

            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Applications by status</h2>
                </div>
                <div class="card-body">
                    @if($overview['statusCounts'] === [])
                        <p class="small text-secondary mb-0">No applications yet.</p>
                    @else
                        <ul class="list-unstyled d-grid gap-2 mb-0">
                            @foreach($overview['statusCounts'] as $status => $count)
                                <li class="d-flex justify-content-between align-items-center gap-2">
                                    <x-status-badge :label="\App\Support\ApplicationStatus::displayLabel($status)"
                                                    :tone="\App\Support\ApplicationStatus::badgeTone($status)" />
                                    <span class="fw-semibold">{{ $count }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>

@endsection
