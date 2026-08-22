@extends('layouts.app')

@section('title', 'Security and privacy')

@section('content')

    <x-page-header title="Security &amp; privacy"
                   subtitle="Your password, what we email you about, and a copy of your data." />

    <div class="row g-4">
        <div class="col-xl-6">

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Change password</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('account.password') }}" novalidate>
                        @csrf

                        <x-form.input name="current_password" label="Current password" type="password" required
                                      autocomplete="current-password" />
                        <x-form.input name="password" label="New password" type="password" required
                                      autocomplete="new-password"
                                      hint="At least 8 characters, letters and numbers." />
                        <x-form.input name="password_confirmation" label="Confirm new password" type="password" required
                                      autocomplete="new-password" />

                        <button class="btn btn-primary" type="submit">Update password</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Your data</h2>
                </div>
                <div class="card-body">
                    <p class="text-secondary">
                        Download everything ScholarZim holds about you &mdash; your account, profile,
                        applications, and saved scholarships &mdash; as a JSON file.
                    </p>
                    <a class="btn btn-outline-secondary d-inline-flex align-items-center gap-2"
                       href="{{ route('account.export') }}">
                        <x-icon name="download" :size="16" />Export my data
                    </a>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Email notifications</h2>
                </div>
                <div class="card-body">
                    <p class="text-secondary small">
                        These control email only. Everything still appears in your notification centre.
                    </p>

                    <form method="POST" action="{{ route('account.notifications') }}">
                        @csrf

                        @foreach([
                            'email_notify_applications' => ['Applications', 'Status changes on applications you submitted or received.'],
                            'email_notify_scholarships' => ['Scholarships', 'New listings that match you, and deadline reminders.'],
                            'email_notify_system' => ['System', 'Account, verification, and administrative messages.'],
                        ] as $field => [$label, $help])
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="{{ $field }}" name="{{ $field }}" value="1"
                                       @checked($user->{$field})>
                                <label class="form-check-label" for="{{ $field }}">
                                    <span class="fw-semibold d-block">{{ $label }}</span>
                                    <span class="small text-secondary">{{ $help }}</span>
                                </label>
                            </div>
                        @endforeach

                        <button class="btn btn-primary" type="submit">Save preferences</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Account</h2>
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        @foreach([
                            'Email address' => $user->email,
                            'Role' => \App\Support\RoleNames::displayLabel($user->roleName()),
                            'Account status' => \App\Support\AccountStatus::displayLabel($user->account_status),
                            'Email verified' => $user->email_verified ? 'Yes' : 'Not yet',
                        ] as $label => $value)
                            <dt class="small text-secondary fw-normal">{{ $label }}</dt>
                            <dd class="fw-semibold">{{ $value }}</dd>
                        @endforeach
                    </dl>

                    @unless($user->email_verified)
                        <form method="POST" action="{{ route('verification.resend') }}" class="mt-3">
                            @csrf
                            <button class="btn btn-sm btn-outline-primary" type="submit">Resend verification email</button>
                        </form>
                    @endunless
                </div>
            </div>
        </div>
    </div>

@endsection
