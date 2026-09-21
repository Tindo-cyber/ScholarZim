@extends('layouts.app')

@section('title', 'Users')

@php
    $active = collect($filters)->filter(fn ($value) => filled($value));
@endphp

@section('content')

    <x-page-header title="Users"
                   :subtitle="number_format($users->total()) . ' account(s) on the platform.'"
                   eyebrow="Administration">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('admin.users.create') }}">Create user</a>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" action="{{ route('admin.users.index') }}" class="card mb-4" role="search">
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
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" type="submit">
                    Filter
                    @if($active->isNotEmpty())
                        <span class="badge bg-white text-primary ms-1">{{ $active->count() }}</span>
                    @endif
                </button>
                @if($active->isNotEmpty())
                    {{-- Clearing has to be a link back to the bare route: a reset
                         button would only restore the values the page loaded with. --}}
                    <a class="btn btn-outline-secondary" href="{{ route('admin.users.index') }}">Clear</a>
                @endif
            </div>
        </div>
    </form>

    <div class="card">
        <x-data-table :columns="[
                          'User',
                          'Role',
                          'Status',
                          'Registered',
                          ['label' => 'Actions', 'align' => 'end'],
                      ]"
                      :empty="$users->isEmpty()"
                      :empty-title="$active->isNotEmpty() ? 'No users match those filters' : 'No accounts yet'"
                      :empty-message="$active->isNotEmpty()
                          ? 'Clear the filters to see every account, or search for part of a name or email address.'
                          : 'Accounts appear here as people register, and you can create one yourself.'"
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

                    {{--
                        When the account was opened. An administration screen that
                        lists accounts and cannot say how old any of them are makes
                        "is this a real organisation or something that signed up an
                        hour ago?" a question you have to leave the page to answer.
                    --}}
                    <x-data-table.cell label="Registered" class="small text-secondary text-nowrap">
                        {{ $user->created_at?->format('d M Y') ?? 'Unknown' }}
                    </x-data-table.cell>

                    <x-data-table.cell label="Actions" align="end">
                        @if($user->is_super_admin || $user->user_id === auth()->id())
                            {{--
                                Say why there is nothing here. An empty action cell
                                reads as a page that failed to render its buttons,
                                and on a phone - where the row becomes a card with a
                                labelled "Actions" line - it reads that way loudly.
                            --}}
                            <span class="small text-secondary">
                                {{ $user->user_id === auth()->id() ? 'Your own account' : 'Protected account' }}
                            </span>
                        @else
                            <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                @if(strcasecmp((string) $user->account_status, \App\Support\AccountStatus::ACTIVE) === 0)
                                    {{--
                                        Suspension ends the account's live sessions,
                                        so the person is signed out wherever they
                                        happen to be at the time. That is worth one
                                        sentence before it happens rather than a
                                        support ticket after it.
                                    --}}
                                    <x-confirm-dialog :id="'suspend-user-' . $user->user_id"
                                                      :action="route('admin.users.suspend', $user->user_id)"
                                                      :title="'Suspend ' . $user->email . '?'"
                                                      trigger-label="Suspend"
                                                      trigger-class="btn btn-sm btn-outline-warning"
                                                      confirm-label="Suspend account"
                                                      tone="warning"
                                                      message="They are signed out everywhere immediately and cannot sign in again until you reactivate them. Nothing is deleted, and you can reverse this from this page." />
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
                        @endif
                    </x-data-table.cell>
                </tr>
            @endforeach
        </x-data-table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>

@endsection
