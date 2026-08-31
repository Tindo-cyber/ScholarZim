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
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 sz-table-stack">
                            <thead>
                                <tr>
                                    <th scope="col">Listing</th>
                                    <th scope="col" class="text-end">Views</th>
                                    <th scope="col" class="text-end">Saves</th>
                                    <th scope="col" class="text-end">Applications</th>
                                    <th scope="col" class="text-end">Accepted</th>
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
                                        <td data-label="Accepted" class="text-end">{{ number_format($row['accepted']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
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
