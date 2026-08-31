@php
    $user = auth()->user();
@endphp

<header class="sz-topbar border-bottom bg-body sticky-top">
    <div class="d-flex align-items-center gap-3 px-3 px-lg-4 py-2">

        <button class="btn border-0 d-xl-none px-1" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#szSidebar" aria-label="Open menu">
            <x-icon name="menu" />
        </button>

        <form class="d-none d-md-block flex-grow-1" style="max-width: 28rem;"
              action="{{ $user->isAdmin() ? route('admin.search') : route('opportunities.index') }}" method="GET">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-body border-end-0"><x-icon name="search" /></span>
                <input type="search"
                       class="form-control border-start-0"
                       name="{{ $user->isAdmin() ? 'q' : 'keyword' }}"
                       value="{{ request('q') ?? request('keyword') }}"
                       placeholder="{{ $user->isAdmin() ? 'Search users, listings, applications' : 'Search scholarships' }}">
            </div>
        </form>

        <div class="ms-auto d-flex align-items-center gap-2">

            <x-theme-toggle />

            <div class="dropdown">
                <button class="btn border-0 position-relative px-2" type="button" data-bs-toggle="dropdown"
                        aria-expanded="false" aria-label="Notifications">
                    <x-icon name="bell" />
                    @if(($unreadNotifications ?? 0) > 0)
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            {{ $unreadNotifications > 9 ? '9+' : $unreadNotifications }}
                            <span class="visually-hidden">unread notifications</span>
                        </span>
                    @endif
                </button>

                <div class="dropdown-menu dropdown-menu-end p-0 shadow" style="width: 22rem;">
                    <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                        <span class="fw-semibold">Notifications</span>
                        @if(($unreadNotifications ?? 0) > 0)
                            <form method="POST" action="{{ route('notifications.readAll') }}" class="m-0">
                                @csrf
                                <button class="btn btn-sm btn-link p-0 text-decoration-none" type="submit">Mark all read</button>
                            </form>
                        @endif
                    </div>

                    <div class="list-group list-group-flush" style="max-height: 20rem; overflow-y: auto;">
                        @forelse(($recentNotifications ?? collect()) as $notification)
                            <a class="list-group-item list-group-item-action d-flex gap-2 {{ $notification->is_read ? '' : 'bg-body-secondary' }}"
                               href="{{ route('notifications.open', $notification->notification_id) }}">
                                <span class="badge rounded-circle bg-{{ $notification->tone() }}-subtle text-{{ $notification->tone() }} flex-shrink-0">
                                    <x-icon :name="$notification->icon()" />
                                </span>
                                <span class="min-w-0">
                                    <span class="d-block small">{{ $notification->message }}</span>
                                    <span class="d-block small text-secondary">{{ $notification->created_at?->diffForHumans() }}</span>
                                </span>
                            </a>
                        @empty
                            <div class="px-3 py-4 text-center text-secondary small">Nothing here yet.</div>
                        @endforelse
                    </div>

                    <a class="d-block text-center small py-2 border-top text-decoration-none"
                       href="{{ route('notifications.index') }}">View all notifications</a>
                </div>
            </div>

            <div class="dropdown">
                <button class="btn border-0 d-flex align-items-center gap-2 px-2" type="button"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <x-avatar :user="$user" size="sm" />
                    <span class="d-none d-lg-inline small fw-semibold">{{ $user->displayName() }}</span>
                </button>

                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li><span class="dropdown-header">{{ $user->email }}</span></li>
                    <li><hr class="dropdown-divider"></li>
                    @if($user->isApplicant())
                        <li><a class="dropdown-item" href="{{ route('applicant.profile') }}">My profile</a></li>
                    @endif
                    <li><a class="dropdown-item" href="{{ route('account.security') }}">Security &amp; privacy</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}" class="m-0">
                            @csrf
                            <button class="dropdown-item text-danger" type="submit">Sign out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</header>
