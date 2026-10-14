@extends('layouts.app')

@section('title', 'Review ' . $provider->displayName())

@use('App\Support\AccountStatus')
@use('App\Support\ProviderVerificationStatus')

@php
    $verification = $profile->verificationStatus();
    $undecided = ! $profile->isReviewed();
    $notRecorded = '<span class="text-secondary">Not recorded</span>';
@endphp

@section('content')

    {{--
        Everything an administrator needs to verify one organisation, wherever it
        is registered: identity, the registration document, the people behind
        the account, and what has already happened in its review. The country is
        what the organisation says, shown beside the document that has to bear
        it out - choosing a country proves nothing on its own.
    --}}
    <x-page-header :title="$provider->displayName()"
                   subtitle="Organisation verification record"
                   eyebrow="Provider verification">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('admin.dashboard') }}#provider-verification">Verification queue</a>
        </x-slot:actions>
    </x-page-header>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
        <x-status-badge :label="ProviderVerificationStatus::label($verification)" :tone="ProviderVerificationStatus::tone($verification)" />
        <span class="small text-secondary">
            Account: {{ AccountStatus::displayLabel($provider->account_status) }}
            &middot; Email {{ $provider->email_verified ? 'verified' : 'not yet verified' }}
        </span>
    </div>

    @if(! $profile->hasRegistrationCountry())
        <div class="alert alert-warning" role="status" id="country-missing">
            <h2 class="h6 fw-semibold mb-1">Country of registration not recorded</h2>
            <p class="small mb-0">
                This account was registered before ScholarZim asked where an organisation is registered. Nothing has
                been assumed. Record the country from the registration document below
                @if($undecided)
                    before verifying it.
                @else
                    - the account, its listings and applications are unaffected either way.
                @endif
            </p>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header"><h2 class="h6 fw-semibold mb-0">Organisation</h2></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-5">Registered name</dt>
                        <dd class="col-sm-7">{{ $provider->displayName() }}</dd>

                        <dt class="col-sm-5">Country of registration</dt>
                        <dd class="col-sm-7" id="registration-country">
                            {!! $profile->hasRegistrationCountry() ? e($profile->registrationCountryName()) . ' <span class="text-secondary">(' . e($profile->registration_country) . ')</span>' : $notRecorded !!}
                        </dd>

                        <dt class="col-sm-5">Organisation type</dt>
                        <dd class="col-sm-7">{{ $profile->organisationTypeLabel() }}</dd>

                        <dt class="col-sm-5">Registration number</dt>
                        <dd class="col-sm-7">{!! $profile->registration_number ? e($profile->registration_number) : $notRecorded !!}</dd>

                        @if($profile->registration_number_note)
                            <dt class="col-sm-5">Why there is no number</dt>
                            <dd class="col-sm-7" style="white-space: pre-line">{{ $profile->registration_number_note }}</dd>
                        @endif

                        <dt class="col-sm-5">Registration document</dt>
                        <dd class="col-sm-7">
                            @if($profile->certificate_filename)
                                <a class="text-decoration-none d-inline-flex align-items-center gap-1"
                                   href="{{ route('admin.providers.certificate', $provider->user_id) }}"
                                   target="_blank" rel="noopener">
                                    <x-icon name="eye" :size="14" />{{ $profile->certificate_filename }}
                                </a>
                                <span class="d-block text-secondary">Opening it is recorded in the audit log.</span>
                            @else
                                {!! $notRecorded !!}
                            @endif
                        </dd>

                        <dt class="col-sm-5">Website</dt>
                        <dd class="col-sm-7">
                            @if($profile->website)
                                <a href="{{ $profile->website }}" target="_blank" rel="noopener noreferrer"
                                   class="text-decoration-none">{{ $profile->website }}</a>
                                <span class="d-block {{ $profile->hasConfirmedWebsite() ? 'text-success' : 'text-secondary' }}">
                                    {{ $profile->hasConfirmedWebsite() ? 'Confirmed by ' . $profile->website_verified_by : 'Not confirmed' }}
                                </span>
                            @else
                                <span class="text-secondary">None given</span>
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h2 class="h6 fw-semibold mb-0">Contact</h2></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-5">Contact person</dt>
                        <dd class="col-sm-7">{!! $profile->contact_name ? e($profile->contact_name) : $notRecorded !!}</dd>

                        <dt class="col-sm-5">Position</dt>
                        <dd class="col-sm-7">{!! $profile->contact_role ? e($profile->contact_role) : $notRecorded !!}</dd>

                        <dt class="col-sm-5">Email</dt>
                        <dd class="col-sm-7">{{ $provider->email }}</dd>

                        <dt class="col-sm-5">Phone</dt>
                        <dd class="col-sm-7">{!! $provider->phone ? e($provider->phone) : $notRecorded !!}</dd>
                    </dl>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h2 class="h6 fw-semibold mb-0">Review history</h2></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-5">Submitted</dt>
                        <dd class="col-sm-7">{{ $profile->submitted_at?->format('d M Y H:i') ?? 'Not recorded' }}</dd>

                        @if($profile->info_requested_at)
                            <dt class="col-sm-5">More information requested</dt>
                            <dd class="col-sm-7">
                                {{ $profile->info_requested_at->format('d M Y H:i') }} by {{ $profile->info_requested_by }}
                                <span class="d-block" style="white-space: pre-line">"{{ $profile->info_request_note }}"</span>
                            </dd>
                        @endif

                        @if($profile->info_provided_at)
                            <dt class="col-sm-5">Organisation updated its details</dt>
                            <dd class="col-sm-7">{{ $profile->info_provided_at->format('d M Y H:i') }}</dd>
                        @endif

                        <dt class="col-sm-5">Decision</dt>
                        <dd class="col-sm-7">
                            @if($profile->reviewed_at)
                                {{ ProviderVerificationStatus::label($verification) }} on {{ $profile->reviewed_at->format('d M Y H:i') }}
                                by {{ $profile->reviewed_by ?? 'an administrator' }}
                                @if($profile->rejection_reason)
                                    <span class="d-block">Reason given: "{{ $profile->rejection_reason }}"</span>
                                @endif
                            @else
                                <span class="text-secondary">None yet</span>
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            @if($undecided)
                <div class="card mb-4" id="decision">
                    <div class="card-header"><h2 class="h6 fw-semibold mb-0">Decision</h2></div>
                    <div class="card-body">
                        <p class="small text-secondary">
                            The same checks apply wherever an organisation is registered: the document should name this
                            organisation and support the country, type and registration number given. Being registered
                            outside Zimbabwe is not a reason to refuse, nor a reason to skip the check.
                        </p>

                        <div class="d-flex flex-wrap gap-2">
                            @if($profile->hasRegistrationCountry())
                                <x-confirm-dialog id="verify-provider"
                                                  :action="route('admin.providers.approve', $provider->user_id)"
                                                  :title="'Verify ' . $provider->displayName() . '?'"
                                                  trigger-label="Approve"
                                                  trigger-class="btn btn-success"
                                                  confirm-label="Verify provider"
                                                  tone="success"
                                                  :message="'Registered in ' . $profile->registrationCountryName() . '. They can publish scholarships from that moment, under the same review and trust rules as every provider.'">
                                    @if($profile->website)
                                        <div class="form-check mt-3">
                                            <input class="form-check-input" type="checkbox" name="website_checked" value="1" id="website-checked">
                                            <label class="form-check-label" for="website-checked">
                                                I have checked that {{ $profile->website }} is this organisation's own website.
                                            </label>
                                        </div>
                                    @endif
                                </x-confirm-dialog>
                            @else
                                <button class="btn btn-success" type="button" disabled
                                        aria-describedby="country-missing">Approve</button>
                            @endif

                            <x-confirm-dialog id="request-information"
                                              :action="route('admin.providers.requestInformation', $provider->user_id)"
                                              :title="'Ask ' . $provider->displayName() . ' for more information'"
                                              trigger-label="Request more information"
                                              trigger-class="btn btn-outline-primary"
                                              confirm-label="Send request"
                                              tone="primary"
                                              message="The organisation stays unable to publish. It sees your note on its dashboard, can update its details or upload a clearer document, and returns to this queue when it does.">
                                <x-form.textarea name="note" id="request-information-note" bag="requestInfo"
                                                 label="What do you need? (sent to the organisation)" :rows="4" required />
                            </x-confirm-dialog>

                            <x-confirm-dialog id="reject-provider"
                                              :action="route('admin.providers.reject', $provider->user_id)"
                                              :title="'Reject ' . $provider->displayName() . '?'"
                                              trigger-label="Reject"
                                              trigger-class="btn btn-outline-danger"
                                              confirm-label="Reject provider"
                                              message="The organisation is told it cannot publish, and sees your reason word for word.">
                                <x-form.textarea name="reason" id="provider-reason"
                                                 label="Reason (sent to the organisation)" :rows="4" required />
                            </x-confirm-dialog>
                        </div>
                    </div>
                </div>
            @endif

            <div class="card mb-4" id="registration-details">
                <div class="card-header"><h2 class="h6 fw-semibold mb-0">Registration details</h2></div>
                <div class="card-body">
                    <p class="small text-secondary">
                        Record or correct what the registration document shows - for example the country of an account
                        registered before it was asked. Saving changes nothing about the account's status, listings or
                        applications, and is recorded in the audit log.
                    </p>
                    <form method="POST" action="{{ route('admin.providers.registration', $provider->user_id) }}">
                        @csrf
                        @include('providers.partials.registration-fields', [
                            'countries' => $countries,
                            'profile' => $profile,
                            'bag' => 'registration',
                            'idPrefix' => 'admin-reg',
                            'audience' => 'admin',
                        ])
                        <x-submit-button label="Save registration details" size="sm" tone="outline-primary" busy-label="Saving..." />
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
