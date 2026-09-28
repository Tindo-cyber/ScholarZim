@extends('reports.layout')

@section('body')
    @forelse($sections as $section)
        <h2>{{ $section['applicant']->full_name }} ({{ $section['applicant']->email }})</h2>
        <table>
            <thead>
            <tr>
                <th style="width:45%">Opportunity</th>
                <th style="width:35%">Provider</th>
                <th style="width:20%">Deadline</th>
            </tr>
            </thead>
            <tbody>
            @foreach($section['matches'] as $match)
                <tr>
                    <td>{{ $match->opportunity->title ?: '—' }}</td>
                    <td>{{ $match->opportunity->provider_name ?: '—' }}</td>
                    <td>{{ $match->opportunity->deadline?->format('d M Y') ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @empty
        <p class="empty">No applicant currently has a scholarship match to report.</p>
    @endforelse
@endsection
