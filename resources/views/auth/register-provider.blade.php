@extends('layouts.auth')

@section('title', 'Register as a provider')
@section('aside_heading', 'Reach verified students.')
@section('aside_copy', 'Publish your programme, review applications in one inbox, and keep every decision on record.')

@section('aside_steps')
    <ul class="list-unstyled d-grid gap-3 mb-0">
        <li class="d-flex gap-3">
            <span class="sz-auth-tick">1</span>
            <span>Register your organisation and contact details.</span>
        </li>
        <li class="d-flex gap-3">
            <span class="sz-auth-tick">2</span>
            <span>Our team verifies your registration certificate.</span>
        </li>
        <li class="d-flex gap-3">
            <span class="sz-auth-tick">3</span>
            <span>Publish listings and review applications in one inbox.</span>
        </li>
    </ul>
@endsection

@php
    $step1Fields = ['full_name', 'email', 'phone', 'organisation_type', 'registration_number', 'certificate'];
    $step2Fields = ['password', 'password_confirmation', 'terms'];
    $initialStep = collect($step1Fields)->contains(fn ($field) => $errors->has($field))
        ? 1
        : (collect($step2Fields)->contains(fn ($field) => $errors->has($field)) ? 2 : 1);
@endphp

@section('content')
    <h1 class="h3 fw-bold mb-1">Register as a provider</h1>
    <p class="text-secondary mb-4">
        Takes about five minutes. We verify every organisation before its listings go live, so
        have your registration certificate ready.
    </p>

    <form method="POST" action="{{ route('register.provider') }}" enctype="multipart/form-data" novalidate
          data-stepped-form data-initial-step="{{ $initialStep }}">
        @csrf

        <div class="d-flex align-items-center gap-3 mb-4">
            <div class="d-flex align-items-center gap-2">
                <span class="sz-form-step" data-step-dot="1">1</span>
                <span class="small" data-step-label="1">Organisation details</span>
            </div>
            <div class="flex-grow-1" style="height:1px;background:var(--bs-border-color);" data-step-line></div>
            <div class="d-flex align-items-center gap-2">
                <span class="sz-form-step" data-step-dot="2">2</span>
                <span class="small" data-step-label="2">Security</span>
            </div>
        </div>

        <div data-step="1">
            <h2 class="h6 fw-semibold text-uppercase text-secondary mb-3">Contact person</h2>

            <x-form.input name="full_name" label="Organisation or contact name" required autocomplete="organization" autofocus />
            <x-form.input name="email" label="Work email address" type="email" required autocomplete="email" />
            <x-form.input name="phone" label="Phone number" type="tel" autocomplete="tel" />

            <h2 class="h6 fw-semibold text-uppercase text-secondary mt-4 mb-3">Organisation</h2>

            <x-form.select name="organisation_type" label="Organisation type" :options="$orgTypes"
                           placeholder="Select a type" required />
            <x-form.input name="registration_number" label="Registration number" required
                          hint="As it appears on your certificate of incorporation or PVO registration." />

            <div class="mb-4">
                <label class="form-label" for="certificate">
                    Registration certificate <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <label class="sz-file-drop d-block mb-0" for="certificate">
                    <x-icon name="upload" :size="22" class="text-primary mb-2" />
                    <div class="fw-semibold small">Click to upload, or drag a file here</div>
                    <div class="form-text mb-0">PDF, Word, JPG, or PNG. Maximum 5 MB.</div>
                    <input class="@error('certificate') is-invalid @enderror" type="file"
                           id="certificate" name="certificate" required
                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" data-file-preview>
                </label>
                <div class="small fw-semibold mt-2 d-none" data-file-preview-for="certificate"></div>

                @error('certificate')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>

            <button class="btn btn-primary btn-lg w-100 mb-3" type="button" data-step-next>Continue</button>
        </div>

        <div data-step="2">
            <h2 class="h6 fw-semibold text-uppercase text-secondary mb-3">Security</h2>

            <x-form.input name="password" label="Password" type="password" required
                          autocomplete="new-password" :strength-check="true" />
            <x-form.input name="password_confirmation" label="Confirm password" type="password" required
                          autocomplete="new-password" />

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

            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-outline-secondary btn-lg" type="button" data-step-back>Back</button>
                <button class="btn btn-primary btn-lg flex-grow-1" type="submit">Submit for verification</button>
            </div>
        </div>
    </form>

    <p class="text-secondary text-center mb-0">
        Already have an account? <a class="text-decoration-none" href="{{ route('login') }}">Sign in</a>
    </p>
@endsection
