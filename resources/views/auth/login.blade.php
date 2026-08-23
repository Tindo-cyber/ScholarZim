@extends('layouts.auth')

@section('title', 'Sign in')
@section('aside_heading', 'Welcome back.')
@section('aside_copy', 'Pick up where you left off — your matches, applications, and deadlines are all waiting.')

@section('content')
    <h1 class="h3 fw-bold mb-1">Sign in</h1>
    <p class="text-secondary mb-4">Use the email address you registered with.</p>

    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf

        <x-form.input name="email" label="Email address" type="email" required autocomplete="email" autofocus />
        <x-form.input name="password" label="Password" type="password" required autocomplete="current-password" />

        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-4">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1"
                       @checked(old('remember'))>
                <label class="form-check-label" for="remember">Keep me signed in</label>
            </div>
            <a class="small text-decoration-none" href="{{ route('password.request') }}">Forgot your password?</a>
        </div>

        <button class="btn btn-primary btn-lg w-100 mb-3" type="submit">Sign in</button>

        <p class="d-flex align-items-center justify-content-center gap-2 small text-secondary mb-0">
            <x-icon name="lock" :size="14" />
            Your credentials are never shared with scholarship providers.
        </p>
    </form>

    <hr class="my-4">

    <p class="text-secondary text-center mb-0">
        New to ScholarZim?
        <a class="text-decoration-none" href="{{ route('register') }}">Create a student account</a>
        or <a class="text-decoration-none" href="{{ route('register.provider') }}">register as a provider</a>.
    </p>
@endsection
