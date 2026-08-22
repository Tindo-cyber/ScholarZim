@extends('emails.layout', ['title' => 'Welcome to ScholarZim'])

@section('body')
    <p style="margin:0 0 12px;">Hi {{ $user->full_name ?: 'there' }},</p>
    <p style="margin:0 0 12px;">
        An account has been created for you on ScholarZim. Sign in with the email address this was
        sent to, then change your password from Security &amp; privacy.
    </p>
    <p style="margin:0;">
        <a href="{{ url('/login') }}" style="color:#0066fe;">Sign in to ScholarZim</a>
    </p>
@endsection
