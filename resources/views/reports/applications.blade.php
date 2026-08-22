@extends('reports.layout')

@section('body')
    <table>
        <thead>
        <tr>
            <th style="width:25%">Applicant</th>
            <th style="width:33%">Opportunity</th>
            <th style="width:17%">Status</th>
            <th style="width:25%">Submitted</th>
        </tr>
        </thead>
        <tbody>
        @forelse($applications as $application)
            <tr>
                <td>{{ $application->user?->full_name ?: '—' }}</td>
                <td>{{ $application->opportunity?->title ?: '—' }}</td>
                <td>{{ $application->application_status ?: '—' }}</td>
                <td>{{ $application->submitted_at?->format('d M Y H:i') ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">No applications recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
