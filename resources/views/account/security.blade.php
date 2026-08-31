@extends('layouts.app')

@section('title', 'Security and privacy')

@section('content')

    <x-page-header title="Security &amp; privacy"
                   subtitle="Your password, your sessions, and what we email you about." />

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

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Other sessions</h2>
                </div>
                <div class="card-body">
                    <p class="text-secondary">
                        Signed in on a library or lab computer and forgot to sign out? This ends every session
                        except this one, on every device.
                    </p>

                    <form method="POST" action="{{ route('account.logoutOthers') }}">
                        @csrf

                        <x-form.input name="current_password" label="Confirm your password" type="password"
                                      required autocomplete="current-password"
                                      id="logout-others-current-password" />

                        <button class="btn btn-outline-primary" type="submit">Sign out all other sessions</button>
                    </form>
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

            <div class="card border-danger">
                <div class="card-header bg-danger-subtle">
                    <h2 class="h6 fw-semibold mb-0">Delete this account</h2>
                </div>
                <div class="card-body">
                    <p class="text-secondary">
                        This removes your profile, applications, saved scholarships, and notifications.
                        It cannot be undone.
                    </p>

                    @if($user->isProvider())
                        <p class="small text-secondary">
                            Withdraw any listing that is still live first &mdash; students' applications point
                            at them, and deleting the listing would erase their history too.
                        </p>
                    @endif

                    <button class="btn btn-outline-danger" type="button"
                            data-bs-toggle="collapse" data-bs-target="#delete-account-panel"
                            aria-expanded="false" aria-controls="delete-account-panel">
                        Delete my account
                    </button>

                    <div class="collapse mt-3" id="delete-account-panel">
                        <form method="POST" action="{{ route('account.destroy') }}">
                            @csrf

                            <x-form.input name="current_password" label="Confirm your password" type="password"
                                          required autocomplete="current-password"
                                          id="delete-current-password" />

                            <x-form.input name="confirm_email" label="Type your email address to confirm"
                                          required :placeholder="$user->email"
                                          hint="A password alone is muscle memory; this is not reversible." />

                            <button class="btn btn-danger" type="submit">Permanently delete my account</button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

@endsection
