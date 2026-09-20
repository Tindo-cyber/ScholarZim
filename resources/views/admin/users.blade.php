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
        <x-data-table :columns="['User', 'Role', 'Status', ['label' => 'Actions', 'align' => 'end']]"
                      :empty="$users->isEmpty()"
                      empty-title="No users match those filters"
                      empty-icon="people">
            @foreach($users as $user)
                        <tr>
                            <x-data-table.cell label="User">
                                <div class="d-flex align-items-center gap-2">
                                    <x-avatar :user="$user" size="sm" />
                                    <div class="min-w-0">
                                        {{-- The badge sits outside the truncating span on purpose.
                                             Inside it, .text-truncate's nowrap measured the name and
                                             the badge as one unbroken line, so on a phone the name
                                             filled the cell and "Super admin" was clipped away
                                             entirely - the one marker on that row that matters. --}}
                                        <span class="fw-semibold d-block text-truncate">{{ $user->displayName() }}</span>
                                        @if($user->is_super_admin)
                                            <span class="badge bg-primary-subtle text-primary">Super admin</span>
                                        @endif
                                        <span class="small text-secondary d-block">{{ $user->email }}</span>
                                    </div>
                                </div>
                            </x-data-table.cell>
                            <x-data-table.cell label="Role">{{ \App\Support\RoleNames::displayLabel($user->roleName()) }}</x-data-table.cell>
                            <x-data-table.cell label="Status">
                                <x-status-badge :label="\App\Support\AccountStatus::displayLabel($user->account_status)"
                                                :tone="\App\Support\AccountStatus::badgeTone($user->account_status)" />
                            </x-data-table.cell>
                            <x-data-table.cell align="end">
                                @unless($user->is_super_admin || $user->user_id === auth()->id())
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

                                        {{--
                                            This was window.confirm(), which could
                                            name the email address and nothing else -
                                            not what deletion takes with it, and not
                                            that it cannot be undone.
                                        --}}
                                        <x-confirm-dialog :id="'delete-user-' . $user->user_id"
                                                          :action="route('admin.users.destroy', $user->user_id)"
                                                          :title="'Delete ' . $user->email . '?'"
                                                          trigger-label="Delete"
                                                          confirm-label="Delete permanently"
                                                          message="This removes the account and everything attached to it - profile, applications, saved scholarships and notifications. It cannot be undone. The audit trail is kept." />
                                    </div>
                                @endunless
                            </x-data-table.cell>
                        </tr>
            @endforeach
        </x-data-table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>

@endsection
