@extends('layouts.auth')

@section('title', 'Verify your email')
@section('aside_heading', 'One last step.')
@section('aside_copy', 'Verifying your address keeps your applications and deadline reminders reaching you.')

@section('content')
    <span class="sz-stat-icon bg-primary-subtle text-primary rounded-3 d-inline-flex align-items-center justify-content-center mb-3">
        <x-icon name="mail" :size="20" />
    </span>

    <h1 class="h3 fw-bold mb-1">Verify your email</h1>
    <p class="text-secondary mb-1">
        We sent a verification link to <strong>{{ auth()->user()->email }}</strong>.
        The link expires in 48 hours.
    </p>
    <p class="text-secondary mb-4">Can't find it? Check your spam or junk folder.</p>

    <form method="POST" action="{{ route('verification.resend') }}" class="mb-3">
        @csrf
        <button class="btn btn-primary btn-lg w-100" type="submit">Resend verification email</button>
    </form>

    <a class="btn btn-outline-secondary w-100" href="{{ route('dashboard') }}">Continue to dashboard</a>
@endsection
