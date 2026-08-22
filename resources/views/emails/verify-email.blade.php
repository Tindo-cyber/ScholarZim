@extends('emails.layout', ['title' => 'Verify your email', 'actionLabel' => 'Verify my email'])

@section('body')
    <p style="margin:0 0 12px;">Welcome to ScholarZim, {{ $user->full_name ?: 'there' }}.</p>
    <p style="margin:0;">
        Confirm this address so we can send you application updates and deadline reminders.
        The link is valid for 48 hours.
    </p>
@endsection
