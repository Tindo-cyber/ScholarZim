@extends('layouts.app')

@section('title', 'Create user')

@section('content')

    <x-page-header title="Create a user"
                   subtitle="Accounts created here are active immediately and skip email verification."
                   eyebrow="Administration" />

    <div class="row">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.users.store') }}" novalidate>
                        @csrf

                        <x-form.input name="full_name" label="Full name" required autofocus />
                        <x-form.input name="email" label="Email address" type="email" required />
                        <x-form.input name="phone" label="Phone number" type="tel" />

                        <x-form.select name="role_name" label="Role"
                                       :options="collect($roles)->mapWithKeys(fn ($r) => [$r => \App\Support\RoleNames::displayLabel($r)])->all()"
                                       placeholder="Select a role" required />

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
                            <button class="btn btn-primary" type="submit">Create account</button>
                            <a class="btn btn-outline-secondary" href="{{ route('admin.users.index') }}">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

@endsection
