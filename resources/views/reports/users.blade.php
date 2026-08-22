@extends('reports.layout')

@section('body')
    <table>
        <thead>
        <tr>
            <th style="width:20%">Full Name</th>
            <th style="width:28%">Email</th>
            <th style="width:16%">Phone</th>
            <th style="width:18%">Role</th>
            <th style="width:18%">Status</th>
        </tr>
        </thead>
        <tbody>
        @forelse($users as $user)
            <tr>
                <td>{{ $user->full_name ?: '—' }}</td>
                <td>{{ $user->email ?: '—' }}</td>
                <td>{{ $user->phone ?: '—' }}</td>
                <td>{{ $user->roleName() ?: '—' }}</td>
                <td>{{ $user->account_status ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">No users recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
