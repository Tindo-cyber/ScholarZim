@extends('layouts.auth')

@section('title', 'Register as a provider')
@section('aside_heading', 'Reach verified students.')
@section('aside_copy', 'Publish your programme, review applications in one inbox, and keep every decision on record.')

@section('aside_steps')
    <ul class="list-unstyled d-grid gap-3 mb-0">
        <li class="d-flex gap-3">
            <span class="sz-auth-tick">1</span>
            <span>Register your organisation and contact details. Organisations registered in any country are welcome.</span>
        </li>
        <li class="d-flex gap-3">
            <span class="sz-auth-tick">2</span>
            <span>Our team verifies your registration document.</span>
        </li>
        <li class="d-flex gap-3">
            <span class="sz-auth-tick">3</span>
            <span>Publish listings and review applications in one inbox.</span>
        </li>
    </ul>
@endsection

@php
    $step1Fields = [
        'full_name', 'registration_country', 'organisation_type', 'registration_number', 'registration_number_note',
        'website', 'certificate', 'contact_name', 'contact_role', 'email', 'phone',
    ];
    $step2Fields = ['password', 'password_confirmation', 'authorised'];
    $initialStep = collect($step1Fields)->contains(fn ($field) => $errors->has($field))
        ? 1
        : (collect($step2Fields)->contains(fn ($field) => $errors->has($field)) ? 2 : 1);
@endphp

@section('content')
    <h1 class="h3 fw-bold mb-1">Register as a provider</h1>
    <p class="text-secondary mb-4">
        Takes about five minutes. Every organisation is verified before it can publish, so have your
        registration certificate or equivalent document ready.
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
            <h2 class="h6 fw-semibold text-uppercase text-secondary mb-3">Organisation</h2>

            <x-form.input name="full_name" label="Organisation's registered name" required autocomplete="organization" autofocus
                          hint="The legal name on your registration document. Students see it on your scholarships." />

            @include('providers.partials.registration-fields', ['countries' => $countries, 'profile' => null])

            <x-form.select name="organisation_type" label="Organisation type" :options="$orgTypes"
                           placeholder="Select a type" required />

            <div class="mb-4">
                <label class="form-label" for="certificate">
                    Registration certificate or equivalent document <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <label class="sz-file-drop d-block mb-0" for="certificate">
                    <x-icon name="upload" :size="22" class="text-primary mb-2" />
                    <div class="fw-semibold small">Click to upload, or drag a file here</div>
                    <div class="form-text mb-0">PDF, Word, JPG, or PNG. Maximum 5 MB.</div>
                    <input class="@error('certificate') is-invalid @enderror" type="file"
                           id="certificate" name="certificate" required
                           aria-describedby="certificate-hint"
                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" data-file-preview>
                </label>
                <div class="form-text" id="certificate-hint">
                    The document that registers your organisation, such as a certificate of incorporation, NPO
                    registration or trust deed. If it is not in English, include a translation in the same file.
                </div>
                <div class="small fw-semibold mt-2 d-none" data-file-preview-for="certificate"></div>

                @error('certificate')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>

            <h2 class="h6 fw-semibold text-uppercase text-secondary mt-4 mb-3">Contact person</h2>

            <x-form.input name="contact_name" label="Your full name" required autocomplete="name" maxlength="255"
                          pattern="{{ \App\Support\FormOptions::NAME_INPUT_PATTERN }}" data-name-input />
            <x-form.input name="contact_role" label="Your position in the organisation" required autocomplete="organization-title"
                          maxlength="150" placeholder="e.g. Scholarships Officer, Director"
                          hint="So we can see you are able to act for the organisation." />
            <x-form.input name="email" label="Work email address" type="email" required autocomplete="email"
                          hint="Preferably an address at your organisation's own domain. You sign in with it and must verify it." />
            <x-form.input name="phone" label="Phone number" type="tel" autocomplete="tel"
                          inputmode="tel" maxlength="30" data-phone-input="international"
                          hint="Optional. Mobile or landline; include the country code outside Zimbabwe, e.g. +44 20 7946 0000." />

            <button class="btn btn-primary btn-lg w-100 mb-3" type="button" data-step-next>Continue</button>
        </div>

        <div data-step="2">
            <h2 class="h6 fw-semibold text-uppercase text-secondary mb-3">Security</h2>

            <x-form.input name="password" label="Password" type="password" required
                          autocomplete="new-password" :strength-check="true" />
            <x-form.input name="password_confirmation" label="Confirm password" type="password" required
                          autocomplete="new-password" />

            <x-form.checkbox name="authorised" id="authorised" required wrapper-class="mb-4"
                             label="I confirm this organisation is registered as stated and I am authorised to act for it." />

            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-outline-secondary btn-lg" type="button" data-step-back>Back</button>
                <x-submit-button label="Submit for verification" size="lg" class="flex-grow-1" busy-label="Submitting..." />
            </div>
        </div>
    </form>

    <p class="text-secondary text-center mb-0">
        Already have an account? <a class="text-decoration-none" href="{{ route('login') }}">Sign in</a>
    </p>
@endsection
