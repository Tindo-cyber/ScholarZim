@extends('reports.layout')

{{-- System activities: the audit log, newest first. Only recorded entries are listed - nothing is generated here. The values
     were redacted when they were written (AuditService), so credentials and secrets are not in this data to begin with. --}}
@section('body')
    @if($truncated)
        <p class="generated">The {{ number_format($limit) }} most recent activities are listed. The Excel export and the audit log screen hold the full history.</p>
    @endif

    <table>
        <thead>
        <tr>
            <th style="width:12%">When</th>
            <th style="width:17%">Actor</th>
            <th style="width:15%">Action</th>
            <th style="width:13%">Record</th>
            <th style="width:25%">Details</th>
            <th style="width:18%">Reason</th>
        </tr>
        </thead>
        <tbody>
        @forelse($entries as $entry)
            <tr>
                <td>{{ $entry->created_at?->format('d M Y H:i') ?: '—' }}</td>
                <td>{{ $entry->actor_email ?: 'System' }}</td>
                <td>{{ \App\Support\AuditAction::displayLabel($entry->action) }}</td>
                <td>{{ $entry->entity_type ? $entry->entity_type . ($entry->entity_id !== null ? ' #' . $entry->entity_id : '') : '—' }}</td>
                <td>{{ $entry->details ?: '—' }}</td>
                <td>{{ $entry->reason ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No activity recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
