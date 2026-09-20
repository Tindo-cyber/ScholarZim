@extends('layouts.app')

@section('title', 'Analytics')

@section('content')

    <x-page-header title="Analytics"
                   subtitle="How your listings are doing, and where your applications stand."
                   eyebrow="Provider">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('provider.applications') }}">
                <x-icon name="inbox" :size="16" /> Applications
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Seven equal cards is a wall. Two headings turn it into "how the
     listings are doing" and "where the applications stand", which is the
     question a provider actually arrives with. --}}
    <h2 class="sz-eyebrow">Listing performance</h2>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-stat-card label="Listings" :value="number_format($overview['listings'])"
                         icon="stars" tone="primary" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Views" :value="number_format($overview['views'])"
                         icon="eye" tone="info" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Saves" :value="number_format($overview['saves'])"
                         icon="bookmark" tone="secondary" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Applications" :value="number_format($overview['applications'])"
                         icon="file-text" tone="warning" />
        </div>
    </div>

    <h2 class="sz-eyebrow">Application decisions</h2>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-4">
            {{-- The number a provider actually acts on, so it leads. --}}
            <x-stat-card label="Awaiting your decision" :value="number_format($overview['pending'])"
                         icon="hourglass-split" tone="warning" />
        </div>
        <div class="col-6 col-xl-4">
            <x-stat-card label="Accepted" :value="number_format($overview['accepted'])"
                         icon="check-circle" tone="success" />
        </div>
        <div class="col-6 col-xl-4">
            <x-stat-card label="Rejected" :value="number_format($overview['rejected'])"
                         icon="x-circle" tone="danger" />
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">By listing</h2>
                </div>

                @if($overview['byListing'] === [])
                    <x-empty-state title="Nothing to show yet"
                                   message="Post a scholarship and its numbers appear here."
                                   icon="stars"
                                   action-label="Post a scholarship"
                                   :action-href="route('opportunities.create')" />
                @else
                    {{--
                        A comparison matrix, kept as one: the point of these columns is
                        reading them across a row and down a column, which a set of cards
                        would lose. It takes the shared table chrome so it looks like
                        every other table in the app, and stacks on a phone like them too.
                    --}}
                    <x-data-table :columns="[
                        'Listing',
                        ['label' => 'Views', 'align' => 'end'],
                        ['label' => 'Saves', 'align' => 'end'],
                        ['label' => 'Applications', 'align' => 'end'],
                        ['label' => 'Accepted', 'align' => 'end'],
                    ]" :hover="false">
                        @foreach($overview['byListing'] as $row)
                            <tr>
                                <x-data-table.cell label="Listing">
                                    <a class="fw-semibold text-decoration-none d-block"
                                       href="{{ route('scholarships.show', $row['opportunity']->opportunity_id) }}">
                                        {{ $row['opportunity']->title }}
                                    </a>
                                    <span class="small text-secondary">{{ $row['opportunity']->lifecycleLabel() }}</span>
                                </x-data-table.cell>
                                <x-data-table.cell label="Views" align="end" class="sz-tabular">{{ number_format($row['views']) }}</x-data-table.cell>
                                <x-data-table.cell label="Saves" align="end" class="sz-tabular">{{ number_format($row['saves']) }}</x-data-table.cell>
                                <x-data-table.cell label="Applications" align="end" class="sz-tabular">{{ number_format($row['applications']) }}</x-data-table.cell>
                                <x-data-table.cell label="Accepted" align="end" class="sz-tabular">{{ number_format($row['accepted']) }}</x-data-table.cell>
                            </tr>
                        @endforeach
                    </x-data-table>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Moderation</h2>
                </div>
                <div class="card-body">
                    <p class="small text-secondary">
                        Every listing is reviewed by an administrator before students can see it.
                    </p>
                    <ul class="list-unstyled d-grid gap-2 mb-0">
                        @foreach([
                            ['Live', $overview['moderation']['approved'], 'success'],
                            ['Awaiting review', $overview['moderation']['pending'], 'warning'],
                            ['Needs changes', $overview['moderation']['rejected'], 'danger'],
                        ] as [$label, $count, $tone])
                            <li class="d-flex justify-content-between align-items-center gap-2">
                                <x-status-badge :label="$label" :tone="$tone" />
                                <span class="fw-semibold">{{ number_format($count) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

@endsection
