@extends('layouts.app')

@section('title', 'Analytics')

@php
    // Charts are rendered as inline SVG/CSS rather than through a charting
    // library, so the page has no runtime dependency on the theme bundle.
    $tones = ['primary', 'success', 'warning', 'danger', 'info', 'secondary'];

    $barChart = function (array $series) {
        $max = max(1, max($series['data'] ?: [0]));

        return collect($series['labels'])->map(fn ($label, $i) => [
            'label' => $label,
            'value' => $series['data'][$i] ?? 0,
            'height' => (int) round((($series['data'][$i] ?? 0) / $max) * 100),
        ]);
    };
@endphp

@section('content')

    {{--
        The same charts as before, under three headings.

        Nothing was added: this page already answered how many, over what
        period, and in what proportion - it just presented seven cards of equal
        weight and left the reader to work out which question each one was
        answering. Totals are a snapshot, trends are a direction, and the mix is
        a composition; they are three different questions and now they say so.
    --}}
    <x-page-header title="Analytics"
                   subtitle="How the platform is being used over the last twelve months."
                   eyebrow="Insights" />

    <h2 class="h5 fw-bold mb-3">Totals today</h2>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-stat-card label="Users" :value="number_format($stats['totalUsers'])" icon="people" tone="primary"
                         :hint="number_format($stats['providers']) . ' providers'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Listings" :value="number_format($stats['totalOpportunities'])" icon="stars" tone="info"
                         :hint="number_format($stats['activeOpportunities']) . ' live now'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Applications" :value="number_format($stats['totalApplications'])" icon="file-text" tone="warning"
                         :hint="number_format($stats['pendingApplications']) . ' awaiting a decision'" />
        </div>
        <div class="col-6 col-xl-3">
            {{-- Accepting an application is granting the scholarship. --}}
            <x-stat-card label="Granted" :value="number_format($stats['acceptedApplications'])" icon="check-circle" tone="success"
                         :hint="number_format($stats['rejectedApplications']) . ' not successful'" />
        </div>
    </div>

    <h2 class="h5 fw-bold mb-3">Trends over the last twelve months</h2>

    <div class="row g-4 mb-4">
        @foreach([
            ['Applications per month', $applicationsPerMonth, 'primary'],
            ['Listings published per month', $opportunitiesPerMonth, 'info'],
            ['Sign-ups per month', $signupsPerMonth, 'success'],
        ] as [$title, $series, $tone])
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="h6 fw-semibold mb-0">{{ $title }}</h3>
                    </div>
                    <div class="card-body">
                        @if(array_sum($series['data'] ?: [0]) === 0)
                            <x-empty-state title="Nothing recorded in this period"
                                           message="The chart fills in as records are created; twelve empty months means there have not been any yet."
                                           icon="chart" level="h4" />
                        @else
                            {{--
                                gap-1 below sm, gap-2 above. Twelve months of bars and
                                twelve labels leave 11 gaps; at 8px each that is 88px of
                                the 320px a small phone has, and the labels - unlike the
                                bars - cannot shrink below their own text, so the row ran
                                2px past the viewport. The two rows must carry the same
                                gap or the labels stop lining up with their bars.
                            --}}
                            <div class="d-flex align-items-end gap-1 gap-sm-2" style="height: 12rem;">
                                @foreach($barChart($series) as $bar)
                                    <div class="flex-fill d-flex flex-column justify-content-end align-items-center h-100"
                                         title="{{ $bar['label'] }}: {{ $bar['value'] }}">
                                        <span class="small text-secondary mb-1">{{ $bar['value'] ?: '' }}</span>
                                        <div class="w-100 bg-{{ $tone }} rounded-top"
                                             style="height: {{ max(2, $bar['height']) }}%;"
                                             role="img"
                                             aria-label="{{ $bar['label'] }}: {{ $bar['value'] }}"></div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="d-flex gap-1 gap-sm-2 mt-2">
                                @foreach($series['labels'] as $label)
                                    <span class="flex-fill text-center text-secondary" style="font-size: .625rem;">
                                        {{ Str::before($label, ' ') }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <h2 class="h5 fw-bold mb-3">Composition</h2>

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <h3 class="h6 fw-semibold mb-0">Application outcomes</h3>
                </div>
                <div class="card-body">
                    @if(empty($statusMix['data']))
                        <x-empty-state title="No applications yet"
                                       message="Once students start applying, this shows how those applications have been decided."
                                       icon="file-text" level="h4" />
                    @else
                        @php $total = max(1, array_sum($statusMix['data'])); @endphp

                        <div class="progress mb-3" style="height: 1.25rem;">
                            @foreach($statusMix['labels'] as $i => $label)
                                @php $share = round(($statusMix['data'][$i] / $total) * 100, 1); @endphp
                                <div class="progress-bar bg-{{ $tones[$i % count($tones)] }}"
                                     style="width: {{ $share }}%"
                                     role="progressbar"
                                     aria-label="{{ $label }}"
                                     aria-valuenow="{{ $share }}" aria-valuemin="0" aria-valuemax="100">
                                    {{ $share >= 8 ? $share . '%' : '' }}
                                </div>
                            @endforeach
                        </div>

                        <ul class="list-unstyled d-grid gap-2 mb-0">
                            @foreach($statusMix['labels'] as $i => $label)
                                <li class="d-flex align-items-center gap-2 small">
                                    <span class="badge bg-{{ $tones[$i % count($tones)] }} rounded-circle p-1">&nbsp;</span>
                                    <span class="flex-grow-1">{{ $label }}</span>
                                    <span class="fw-semibold sz-tabular">{{ $statusMix['data'][$i] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <h3 class="h6 fw-semibold mb-0">Most-listed fields of study</h3>
                </div>
                <div class="card-body">
                    @if(empty($topFields['data']))
                        <x-empty-state title="No published listings yet"
                                       message="This ranks the fields providers are funding, and fills in once listings are approved."
                                       icon="stars" level="h4" />
                    @else
                        @php $max = max($topFields['data']); @endphp

                        <ul class="list-unstyled d-grid gap-3 mb-0">
                            @foreach($topFields['labels'] as $i => $label)
                                <li>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span>{{ $label }}</span>
                                        <span class="fw-semibold sz-tabular">{{ $topFields['data'][$i] }}</span>
                                    </div>
                                    <div class="progress sz-progress-thin" role="progressbar"
                                         aria-label="{{ $label }}"
                                         aria-valuenow="{{ $topFields['data'][$i] }}"
                                         aria-valuemin="0" aria-valuemax="{{ $max }}">
                                        <div class="progress-bar"
                                             style="width: {{ round(($topFields['data'][$i] / $max) * 100) }}%"></div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>

@endsection
