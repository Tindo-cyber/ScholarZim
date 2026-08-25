@extends('layouts.auth')

@section('title', 'Two-factor authentication')

@section('aside_heading', 'One more step.')
@section('aside_copy', 'Your account has a second factor, so a password on its own is never enough to sign in.')

@section('content')

    <h1 class="h3 fw-bold mb-2">Enter your code</h1>
    <p class="text-secondary mb-4">
        Open your authenticator app and type the six-digit code for ScholarZim.
    </p>

    <form method="POST" action="{{ route('two-factor.verify') }}" novalidate>
        @csrf

        <div class="mb-3">
            <label class="form-label" for="two-factor-code">Authentication code</label>
            {{--
                inputmode + autocomplete let a phone offer the code straight from
                the keyboard or SMS autofill rather than making the user switch apps.
            --}}
            <input type="text"
                   class="form-control form-control-lg text-center @error('code') is-invalid @enderror"
                   id="two-factor-code"
                   name="code"
                   inputmode="numeric"
                   autocomplete="one-time-code"
                   autofocus
                   required
                   maxlength="32"
                   placeholder="000000"
                   aria-describedby="two-factor-hint">
            @error('code')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <div class="form-text" id="two-factor-hint">
                Lost your phone? Type one of your recovery codes instead.
                @if($recoveryRemaining > 0)
                    You have {{ $recoveryRemaining }} left.
                @else
                    You have none left — contact an administrator.
                @endif
            </div>
        </div>

        <button class="btn btn-primary btn-lg w-100 mb-3" type="submit">Verify and sign in</button>
    </form>

    <form method="POST" action="{{ route('two-factor.cancel') }}" class="text-center">
        @csrf
        <button class="btn btn-link text-secondary" type="submit">Cancel and sign in as someone else</button>
    </form>

@endsection
