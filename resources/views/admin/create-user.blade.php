@extends('layouts.app')

@section('title', 'Create user')

@section('content')

    <x-page-header title="Create a user"
                   subtitle="Accounts created here are active immediately and skip email verification."
                   eyebrow="Administration" />

    {{--
        What creating an account here actually does, since it is not the same
        path a person signing up takes.

        Every fact below is AdminUserService::createUser(): the account is
        written ACTIVE with email_verified true, a welcome message is sent, and
        the action is audited. The provider note matters most - that method
        creates no provider_profile, so an organisation made here has no
        registration certificate on file and never appears in the verification
        queue. An administrator choosing "Provider" from the list deserves to
        know that before they choose it, not after somebody asks where the
        certificate went.
    --}}
    <div class="alert alert-primary d-flex gap-2 mb-4" role="note">
        <x-icon name="shield" :size="18" class="flex-shrink-0 mt-1" />
        <div>
            <p class="mb-1">
                The account is active straight away, is treated as email-verified, and is sent a
                welcome message. Creating it is recorded in the audit log.
            </p>
            <p class="mb-0">
                A provider created here is not put through verification: there is no registration
                certificate on file and nothing appears in the review queue. Let an organisation
                register itself if you want that check to happen.
            </p>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.users.store') }}" novalidate>
                        @csrf

                        <x-form.input name="full_name" label="Full name" required autofocus
                                      pattern="[\p{L}\s'\-]+" hint="Letters only - no numbers." />
                        <x-form.input name="email" label="Email address" type="email" required />
                        <x-form.input name="phone" label="Phone number" type="tel"
                                      inputmode="numeric" minlength="10" maxlength="10" pattern="\d{10}"
                                      hint="Optional. 10 digits, no spaces or country code, e.g. 0771234567." />

                        <x-form.select name="role_name" label="Role"
                                       :options="collect($roles)->mapWithKeys(fn ($r) => [$r => \App\Support\RoleNames::displayLabel($r)])->all()"
                                       placeholder="Select a role" required
                                       hint="Applicants apply for scholarships, providers publish them, administrators run the platform. A role cannot be changed here afterwards." />

                        <div class="row">
                            <div class="col-md-6">
                                <x-form.input name="password" label="Temporary password" type="password" required
                                              hint="At least 8 characters, letters and numbers." />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="password_confirmation" label="Confirm password" type="password" required />
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <x-submit-button label="Create account" busy-label="Creating..." />
                            <a class="btn btn-outline-secondary" href="{{ route('admin.users.index') }}">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

@endsection
