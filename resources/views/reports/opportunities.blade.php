@extends('reports.layout')

@section('body')
    <table>
        <thead>
        <tr>
            <th style="width:22%">Title</th>
            <th style="width:22%">Provider</th>
            <th style="width:14%">Education</th>
            <th style="width:14%">Field</th>
            <th style="width:14%">Country</th>
            <th style="width:14%">Deadline</th>
        </tr>
        </thead>
        <tbody>
        @forelse($opportunities as $opportunity)
            <tr>
                <td>{{ $opportunity->title ?: '—' }}</td>
                <td>{{ $opportunity->provider_name ?: '—' }}</td>
                <td>{{ $opportunity->education_level ?: '—' }}</td>
                <td>{{ $opportunity->target_field ?: '—' }}</td>
                <td>{{ $opportunity->country ?: '—' }}</td>
                <td>{{ $opportunity->deadline?->format('d M Y') ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No opportunities recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
