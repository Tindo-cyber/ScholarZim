@extends('layouts.app')

@section('title', 'Reports')

@php
    /**
     * The exports that exist, grouped by what they are about rather than by the
     * file they produce.
     *
     * Before this the page was eight buttons in one flat grid, coloured red for
     * PDF and green for Excel - the two tones this application uses everywhere
     * else for "something went wrong" and "something succeeded". Read by
     * category instead, the one asymmetry in the set becomes visible and
     * explainable: recommendations are a PDF only, and saying so is better than
     * leaving a gap in a grid where an Excel button would otherwise be.
     *
     * Nothing here generates anything new. Each entry is an existing route.
     */
    $reports = [
        [
            'title' => 'Users',
            'description' => 'Name, email, phone, role and account status, for every account.',
            'pdf' => 'admin.reports.users.pdf',
            'excel' => 'admin.reports.users.xlsx',
        ],
        [
            'title' => 'Opportunities',
            'description' => 'Title, awarding body, education level, field, location and deadline.',
            'pdf' => 'admin.reports.opportunities.pdf',
            'excel' => 'admin.reports.opportunities.xlsx',
        ],
        [
            'title' => 'Applications',
            'description' => 'Applicant, listing, current status and the date it was submitted.',
            'pdf' => 'admin.reports.applications.pdf',
            'excel' => 'admin.reports.applications.xlsx',
        ],
        [
            'title' => 'Recommendations',
            'description' => 'Listings with their awarding body, ScholarFit match percentage and deadline.',
            'pdf' => 'admin.reports.recommendations.pdf',
            'excel' => null,
        ],
    ];
@endphp

@section('content')

    <x-page-header title="Reports"
                   subtitle="Export platform data for compliance, analysis, and viva evidence."
                   eyebrow="Data exports" />

    <div class="card mb-4">
        <div class="card-header">
            {{-- "Export reports", not "Exports": ReportExportTest asserts on this
                 heading to prove the hub rendered, so the string is load-bearing. --}}
            <h2 class="h6 fw-semibold mb-0">Export reports</h2>
        </div>

        <ul class="list-group list-group-flush">
            @foreach($reports as $report)
                <li class="list-group-item d-flex flex-wrap gap-3 align-items-center justify-content-between">
                    <div class="min-w-0">
                        <span class="fw-semibold d-block">{{ $report['title'] }}</span>
                        <span class="small text-secondary">{{ $report['description'] }}</span>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                           href="{{ route($report['pdf']) }}">
                            <x-icon name="download" :size="14" />PDF
                        </a>

                        @if($report['excel'])
                            <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                               href="{{ route($report['excel']) }}">
                                <x-icon name="download" :size="14" />Excel
                            </a>
                        @else
                            <span class="small text-secondary align-self-center">PDF only</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="card-footer small text-secondary">
            Every export is generated from live data at the moment you ask for it, and reflects
            everything on the platform - not the filters on any other page.
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="h6 fw-semibold mb-0">Elsewhere</h2>
        </div>
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div class="min-w-0">
                    <span class="fw-semibold d-block">Audit history</span>
                    <span class="small text-secondary">
                        Who did what, and when. Filterable by actor, action and entity, and not part of
                        the exports above.
                    </span>
                </div>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.audit') }}">Open audit log</a>
            </li>
            <li class="list-group-item d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div class="min-w-0">
                    <span class="fw-semibold d-block">Platform analytics</span>
                    <span class="small text-secondary">
                        Twelve months of sign-ups, listings and applications, on screen rather than as a file.
                    </span>
                </div>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.analytics') }}">Open analytics</a>
            </li>
        </ul>
    </div>

@endsection
