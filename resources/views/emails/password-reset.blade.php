@extends('emails.layout', ['title' => 'Reset your password', 'actionLabel' => 'Reset my password'])

@section('body')
    <p style="margin:0 0 12px;">Hi {{ $user->full_name ?: 'there' }},</p>
    <p style="margin:0 0 12px;">
        We received a request to reset your ScholarZim password. Use the button below within the next hour.
    </p>
    <p style="margin:0;color:#6b7280;">
        If you did not ask for this, you can ignore this email &mdash; your password stays unchanged.
    </p>
@endsection
