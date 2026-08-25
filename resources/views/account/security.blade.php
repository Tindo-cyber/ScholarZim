@extends('layouts.app')

@section('title', 'Security and privacy')

@section('content')

    <x-page-header title="Security &amp; privacy"
                   subtitle="Your password, your second factor, what we email you about, and a copy of your data." />

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
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">Two-factor authentication</h2>
                    <x-status-badge :label="$twoFactorEnabled ? 'On' : 'Off'"
                                    :tone="$twoFactorEnabled ? 'success' : 'secondary'"
                                    :icon="$twoFactorEnabled ? 'shield-check' : null" />
                </div>
                <div class="card-body">
                    @if($twoFactorEnabled)
                        <p class="text-secondary">
                            Signing in asks for a code from your authenticator app after your password. You have
                            <strong>{{ $recoveryRemaining }}</strong> unused recovery
                            {{ Str::plural('code', $recoveryRemaining) }}.
                        </p>

                        <form method="POST" action="{{ route('account.2fa.disable') }}">
                            @csrf
                            @method('DELETE')

                            <x-form.input name="current_password" label="Confirm your password" type="password"
                                          required autocomplete="current-password"
                                          hint="Turning off a second factor is a security change, so it needs your password." />

                            <button class="btn btn-outline-danger" type="submit">Turn off two-factor</button>
                        </form>
                    @elseif(session('twoFactorSetup'))
                        @php $setup = session('twoFactorSetup'); @endphp

                        <p class="text-secondary">
                            Add this key to your authenticator app (Google Authenticator, Authy, or any TOTP app),
                            then type the code it shows to finish. Two-factor is not on until you do.
                        </p>

                        <div class="mb-3">
                            <label class="form-label" for="totp-secret">Setup key</label>
                            <input class="form-control font-monospace" id="totp-secret" type="text"
                                   value="{{ $setup['formatted'] }}" readonly
                                   onfocus="this.select()">
                            <div class="form-text">
                                Choose "enter a setup key" in your app, with ScholarZim as the account name.
                            </div>
                        </div>

                        <details class="mb-3">
                            <summary class="small fw-semibold">Full otpauth link</summary>
                            <code class="small d-block mt-2 text-break">{{ $setup['uri'] }}</code>
                        </details>

                        <div class="alert alert-warning">
                            <div class="fw-semibold mb-1">Save your recovery codes now</div>
                            <p class="small mb-2">
                                Each works once, and they are the only way back in if you lose your phone.
                                They are not shown again.
                            </p>
                            <ul class="list-unstyled font-monospace small mb-0 row row-cols-2 g-1">
                                @foreach($setup['recovery'] as $code)
                                    <li class="col">{{ $code }}</li>
                                @endforeach
                            </ul>
                        </div>

                        <form method="POST" action="{{ route('account.2fa.confirm') }}">
                            @csrf

                            <x-form.input name="code" label="Code from your app" required
                                          inputmode="numeric" autocomplete="one-time-code"
                                          placeholder="000000" />

                            <button class="btn btn-primary" type="submit">Turn on two-factor</button>
                        </form>
                    @else
                        <p class="text-secondary">
                            Add a code from an authenticator app on top of your password. Strongly recommended
                            for administrator accounts, which can see every user on the platform.
                        </p>

                        <form method="POST" action="{{ route('account.2fa.generate') }}">
                            @csrf

                            <x-form.input name="current_password" label="Confirm your password" type="password"
                                          required autocomplete="current-password"
                                          hint="So a session left open on a shared machine cannot bind a second factor to someone else's phone."
                                          id="two-factor-current-password" />

                            <button class="btn btn-primary" type="submit">Set up two-factor</button>
                        </form>
                    @endif
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

            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">API tokens</h2>
                    <a class="small" href="{{ route('api.docs') }}">Read the API docs</a>
                </div>
                <div class="card-body">
                    <p class="text-secondary small">
                        A token lets a program read your applications and ScholarFit recommendations on your
                        behalf. Tokens are read-only and can be revoked at any time.
                    </p>

                    @if(session('newApiToken'))
                        <div class="alert alert-success">
                            <div class="fw-semibold mb-1">Copy this token now</div>
                            <p class="small mb-2">It is shown once and never stored in a readable form.</p>
                            <input class="form-control font-monospace small" type="text" readonly
                                   value="{{ session('newApiToken') }}" onfocus="this.select()"
                                   aria-label="Your new API token">
                        </div>
                    @endif

                    @if($apiTokens->isNotEmpty())
                        <ul class="list-unstyled d-grid gap-2 mb-3">
                            @foreach($apiTokens as $token)
                                <li class="d-flex align-items-center gap-2 border rounded-3 p-2">
                                    <div class="min-w-0 flex-grow-1">
                                        <span class="fw-semibold d-block text-truncate">{{ $token->name }}</span>
                                        <span class="small text-secondary">
                                            Created {{ $token->created_at?->format('d M Y') }}
                                            @if($token->last_used_at)
                                                · last used {{ $token->last_used_at->diffForHumans() }}
                                            @else
                                                · never used
                                            @endif
                                        </span>
                                    </div>

                                    <form method="POST" action="{{ route('account.tokens.destroy', $token->id) }}" class="m-0">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit"
                                                aria-label="Revoke the token {{ $token->name }}">Revoke</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <form method="POST" action="{{ route('account.tokens.store') }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-sm-8">
                            <label class="form-label" for="token-name">Token name</label>
                            <input type="text" class="form-control" id="token-name" name="token_name"
                                   maxlength="60" required placeholder="e.g. My phone app">
                        </div>
                        <div class="col-sm-4 d-grid">
                            <button class="btn btn-outline-primary" type="submit">Create token</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-danger">
                <div class="card-header bg-danger-subtle">
                    <h2 class="h6 fw-semibold mb-0">Delete this account</h2>
                </div>
                <div class="card-body">
                    <p class="text-secondary">
                        This removes your profile, applications, saved scholarships, alerts, and notifications.
                        It cannot be undone, so
                        <a href="{{ route('account.export') }}">export your data</a> first if you want a copy.
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
