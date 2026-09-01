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

    <x-page-header title="Analytics"
                   subtitle="How the platform is being used over the last twelve months."
                   eyebrow="Administration" />

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-stat-card label="Users" :value="number_format($stats['totalUsers'])" icon="people" tone="primary" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Listings" :value="number_format($stats['totalOpportunities'])" icon="stars" tone="info" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Applications" :value="number_format($stats['totalApplications'])" icon="file-text" tone="warning" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Accepted" :value="number_format($stats['acceptedApplications'])" icon="check-circle" tone="success" />
        </div>
    </div>

    <div class="row g-4">
        @foreach([
            ['Applications per month', $applicationsPerMonth, 'primary'],
            ['Listings published per month', $opportunitiesPerMonth, 'info'],
            ['Sign-ups per month', $signupsPerMonth, 'success'],
        ] as [$title, $series, $tone])
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">{{ $title }}</h2>
                    </div>
                    <div class="card-body">
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
                    </div>
                </div>
            </div>
        @endforeach

        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Application outcomes</h2>
                </div>
                <div class="card-body">
                    @if(empty($statusMix['data']))
                        <p class="text-secondary mb-0">No applications recorded yet.</p>
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
                                    <span class="fw-semibold">{{ $statusMix['data'][$i] }}</span>
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
                    <h2 class="h6 fw-semibold mb-0">Most-listed fields of study</h2>
                </div>
                <div class="card-body">
                    @if(empty($topFields['data']))
                        <p class="text-secondary mb-0">No published listings yet.</p>
                    @else
                        @php $max = max($topFields['data']); @endphp

                        <ul class="list-unstyled d-grid gap-3 mb-0">
                            @foreach($topFields['labels'] as $i => $label)
                                <li>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span>{{ $label }}</span>
                                        <span class="fw-semibold">{{ $topFields['data'][$i] }}</span>
                                    </div>
                                    <div class="progress" style="height: .375rem;" role="progressbar"
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
