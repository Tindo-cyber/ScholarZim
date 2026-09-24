@extends('layouts.auth')

@section('title', 'Create your student account')
@section('aside_heading', 'Start matching in minutes.')
@section('aside_copy', 'Create your account, complete your profile, and see scored matches straight away.')

@section('content')
    <h1 class="h3 fw-bold mb-1">Create your student account</h1>
    <p class="text-secondary mb-4">Free, and you can complete your academic profile afterwards.</p>

    <form method="POST" action="{{ route('register') }}" novalidate>
        @csrf

        <x-form.input name="full_name" label="Full name" required autocomplete="name" autofocus
                      pattern="[\p{L}\s'\-]+" hint="Letters only - no numbers." />
        <x-form.input name="email" label="Email address" type="email" required autocomplete="email" />
        <x-form.input name="phone" label="Phone number" type="tel" autocomplete="tel"
                      inputmode="numeric" minlength="10" maxlength="10" pattern="\d{10}"
                      hint="Optional. 10 digits, no spaces or country code, e.g. 0771234567." />

        <x-form.input name="password" label="Password" type="password" required
                      autocomplete="new-password" :strength-check="true" />
        <x-form.input name="password_confirmation" label="Confirm password" type="password" required
                      autocomplete="new-password" />

        <x-form.checkbox name="terms" id="terms" required wrapper-class="mb-4"
                         label="I agree to the ScholarZim terms of use and privacy policy." />

        <x-submit-button label="Create account" size="lg" class="w-100 mb-3" busy-label="Creating..." />
    </form>

    <p class="text-secondary text-center mb-0">
        Already registered? <a class="text-decoration-none" href="{{ route('login') }}">Sign in</a>
    </p>
@endsection
