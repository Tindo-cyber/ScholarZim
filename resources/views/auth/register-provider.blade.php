@extends('layouts.auth')

@section('title', 'Register as a provider')
@section('aside_heading', 'Reach verified students.')
@section('aside_copy', 'Publish your programme, review applications in one inbox, and keep every decision on record.')

@section('content')
    <h1 class="h3 fw-bold mb-1">Register as a provider</h1>
    <p class="text-secondary mb-4">
        We verify every organisation before its listings go live, so have your registration
        certificate ready.
    </p>

    <form method="POST" action="{{ route('register.provider') }}" enctype="multipart/form-data" novalidate>
        @csrf

        <h2 class="h6 fw-semibold text-uppercase text-secondary mb-3">Contact person</h2>

        <x-form.input name="full_name" label="Organisation or contact name" required autocomplete="organization" autofocus />
        <x-form.input name="email" label="Work email address" type="email" required autocomplete="email" />
        <x-form.input name="phone" label="Phone number" type="tel" autocomplete="tel" />

        <h2 class="h6 fw-semibold text-uppercase text-secondary mt-4 mb-3">Organisation</h2>

        <x-form.select name="organisation_type" label="Organisation type" :options="$orgTypes"
                       placeholder="Select a type" required />
        <x-form.input name="registration_number" label="Registration number" required
                      hint="As it appears on your certificate of incorporation or PVO registration." />

        <div class="mb-3">
            <label class="form-label" for="certificate">
                Registration certificate <span class="text-danger" aria-hidden="true">*</span>
            </label>
            <input class="form-control @error('certificate') is-invalid @enderror" type="file"
                   id="certificate" name="certificate" required
                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
            <div class="form-text">PDF, Word, JPG, or PNG. Maximum 5 MB.</div>
            @error('certificate')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <h2 class="h6 fw-semibold text-uppercase text-secondary mt-4 mb-3">Security</h2>

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
                I confirm this organisation is registered and I am authorised to act for it.
            </label>
            @error('terms')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <button class="btn btn-primary btn-lg w-100 mb-3" type="submit">Submit for verification</button>
    </form>

    <p class="text-secondary text-center mb-0">
        Already have an account? <a class="text-decoration-none" href="{{ route('login') }}">Sign in</a>
    </p>
@endsection
