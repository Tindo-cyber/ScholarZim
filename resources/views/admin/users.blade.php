@extends('layouts.app')

@section('title', 'Users')

@section('content')

    <x-page-header title="Users"
                   :subtitle="number_format($users->total()) . ' account(s) on the platform.'"
                   eyebrow="Administration">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('admin.users.create') }}">Create user</a>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" action="{{ route('admin.users.index') }}" class="card mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label" for="q">Search</label>
                <input type="search" class="form-control" id="q" name="q"
                       value="{{ $filters['q'] ?? '' }}" placeholder="Name or email">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="role">Role</label>
                <select class="form-select" id="role" name="role">
                    <option value="">All roles</option>
                    @foreach($roles as $role)
                        <option value="{{ $role }}" @selected(($filters['role'] ?? '') === $role)>
                            {{ \App\Support\RoleNames::displayLabel($role) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">Any</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                            {{ \App\Support\AccountStatus::displayLabel($status) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary" type="submit">Filter</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">User</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <x-avatar :user="$user" size="sm" />
                                    <div class="min-w-0">
                                        <span class="fw-semibold d-block text-truncate">
                                            {{ $user->displayName() }}
                                            @if($user->is_super_admin)
                                                <span class="badge bg-primary-subtle text-primary ms-1">Super admin</span>
                                            @endif
                                        </span>
                                        <span class="small text-secondary">{{ $user->email }}</span>
                                    </div>
                                </div>
                            </td>
                            <td>{{ \App\Support\RoleNames::displayLabel($user->roleName()) }}</td>
                            <td>
                                <x-status-badge :label="\App\Support\AccountStatus::displayLabel($user->account_status)"
                                                :tone="\App\Support\AccountStatus::badgeTone($user->account_status)" />
                            </td>
                            <td class="text-end">
                                @unless($user->is_super_admin)
                                    <div class="d-inline-flex gap-1">
                                        @if(strcasecmp((string) $user->account_status, \App\Support\AccountStatus::ACTIVE) === 0)
                                            <form method="POST" action="{{ route('admin.users.suspend', $user->user_id) }}" class="m-0">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-warning" type="submit">Suspend</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.users.reactivate', $user->user_id) }}" class="m-0">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-success" type="submit">Reactivate</button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('admin.users.destroy', $user->user_id) }}"
                                              class="m-0"
                                              onsubmit="return confirm('Delete {{ $user->email }} permanently?');">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <x-empty-state title="No users match those filters" icon="people" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>

@endsection
