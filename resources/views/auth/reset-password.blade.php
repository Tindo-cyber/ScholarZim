@extends('layouts.auth')

@section('title', 'Choose a new password')
@section('aside_heading', 'Almost there.')
@section('aside_copy', 'Pick something you have not used elsewhere — at least 8 characters with letters and numbers.')

@section('content')
    <h1 class="h3 fw-bold mb-1">Choose a new password</h1>
    <p class="text-secondary mb-4">
        Setting a new password for <strong>{{ $email }}</strong>.
    </p>

    <form method="POST" action="{{ route('password.update', $token) }}" novalidate>
        @csrf

        <x-form.input name="password" label="New password" type="password" required
                      autocomplete="new-password" autofocus :strength-check="true" />
        <x-form.input name="password_confirmation" label="Confirm new password" type="password" required
                      autocomplete="new-password" />

        <button class="btn btn-primary btn-lg w-100" type="submit">Update password</button>
    </form>
@endsection
