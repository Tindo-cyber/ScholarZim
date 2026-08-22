@extends('layouts.app')

@section('title', 'Reports')

@section('content')

    <x-page-header title="Reports"
                   subtitle="Export platform data for compliance, analysis, and viva evidence."
                   eyebrow="Data exports" />

    <div class="card mb-4">
        <div class="card-body p-4">
            <h2 class="h6 fw-bold mb-1">
                <x-icon name="download" class="text-secondary me-1" />Export reports
            </h2>
            <p class="small text-secondary mb-3">Download platform data as PDF or Excel.</p>
            <div class="row g-2">
                @foreach([
                    'admin.reports.users.pdf' => 'Users PDF',
                    'admin.reports.opportunities.pdf' => 'Opportunities PDF',
                    'admin.reports.applications.pdf' => 'Applications PDF',
                    'admin.reports.recommendations.pdf' => 'Recommendations PDF',
                ] as $route => $label)
                    <div class="col-12 col-sm-6 col-lg-3">
                        <a class="btn btn-sm btn-outline-danger w-100" href="{{ route($route) }}">{{ $label }}</a>
                    </div>
                @endforeach

                @foreach([
                    'admin.reports.users.xlsx' => 'Users Excel',
                    'admin.reports.opportunities.xlsx' => 'Opportunities Excel',
                    'admin.reports.applications.xlsx' => 'Applications Excel',
                ] as $route => $label)
                    <div class="col-12 col-sm-6 col-lg-3">
                        <a class="btn btn-sm btn-outline-success w-100" href="{{ route($route) }}">{{ $label }}</a>
                    </div>
                @endforeach

                <div class="col-12 col-sm-6 col-lg-3">
                    <a class="btn btn-sm btn-outline-secondary w-100" href="{{ route('admin.audit') }}">Full audit log</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="h6 fw-bold mb-0">Export notes</h2>
        </div>
        <div class="card-body">
            <ul class="small text-secondary mb-0 ps-3">
                <li class="mb-2">PDF reports are formatted for printing and examiner review.</li>
                <li class="mb-2">Excel exports open in Microsoft Excel or Google Sheets for further analysis.</li>
                <li>Audit history is available separately on the <a href="{{ route('admin.audit') }}">audit log</a> page.</li>
            </ul>
        </div>
    </div>

@endsection
