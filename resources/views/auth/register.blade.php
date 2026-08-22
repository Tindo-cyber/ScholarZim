@extends('layouts.auth')

@section('title', 'Create your student account')
@section('aside_heading', 'Start matching in minutes.')
@section('aside_copy', 'Create your account, complete your profile, and see scored matches straight away.')

@section('content')
    <h1 class="h3 fw-bold mb-1">Create your student account</h1>
    <p class="text-secondary mb-4">Free, and you can complete your academic profile afterwards.</p>

    <form method="POST" action="{{ route('register') }}" novalidate>
        @csrf

        <x-form.input name="full_name" label="Full name" required autocomplete="name" autofocus />
        <x-form.input name="email" label="Email address" type="email" required autocomplete="email" />
        <x-form.input name="phone" label="Phone number" type="tel" autocomplete="tel"
                      hint="Optional. Used only for deadline reminders." />

        <div class="row">
            <div class="col-md-6">
                <x-form.input name="password" label="Password" type="password" required
                              autocomplete="new-password"
                              hint="At least 8 characters, letters and numbers." />
            </div>
            <div class="col-md-6">
                <x-form.input name="password_confirmation" label="Confirm password" type="password" required
                              autocomplete="new-password" />
            </div>
        </div>

        <div class="form-check mb-4">
            <input class="form-check-input @error('terms') is-invalid @enderror" type="checkbox"
                   name="terms" id="terms" value="1" @checked(old('terms')) required>
            <label class="form-check-label" for="terms">
                I agree to the ScholarZim terms of use and privacy policy.
            </label>
            @error('terms')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <button class="btn btn-primary btn-lg w-100 mb-3" type="submit">Create account</button>
    </form>

    <p class="text-secondary text-center mb-0">
        Already registered? <a class="text-decoration-none" href="{{ route('login') }}">Sign in</a>
    </p>
@endsection
