@extends('layouts.auth')

@section('title', 'Reset your password')
@section('aside_heading', 'Locked out?')
@section('aside_copy', 'We will email you a link that stays valid for one hour.')

@section('content')
    <h1 class="h3 fw-bold mb-1">Reset your password</h1>
    <p class="text-secondary mb-4">Enter your email address and we will send you a reset link.</p>

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

        <x-form.input name="email" label="Email address" type="email" required autocomplete="email" autofocus />

        <button class="btn btn-primary btn-lg w-100 mb-3" type="submit">Send reset link</button>
    </form>

    <p class="text-secondary text-center mb-0">
        Remembered it? <a class="text-decoration-none" href="{{ route('login') }}">Back to sign in</a>
    </p>
@endsection
