@php
    $user = auth()->user();
@endphp

{{--
    One topbar, for every authenticated page and every role.

    It deliberately carries no page title: the page's own <x-page-header> owns
    that, and printing it here as well gave every screen two <h1>-looking
    headings a few pixels apart. What it does carry is the things that belong to
    the session rather than to the page - search, theme, notifications, account -
    plus the drawer toggle on anything narrower than xl.
--}}
<header class="sz-topbar border-bottom bg-body sticky-top">
    <div class="sz-topbar-inner d-flex align-items-center gap-2 gap-lg-3 px-3 px-lg-4">

        <button class="btn border-0 d-xl-none px-2" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#szSidebar"
                aria-controls="szSidebar" aria-expanded="false" aria-label="Open menu">
            <x-icon name="menu" />
        </button>

        {{--
            Below md the field is hidden and the icon button beside it takes over:
            a full search box would leave no room for the account controls on a
            360px screen, and tapping the icon lands on the same page the field
            would have submitted to, with the cursor in its own search input.
        --}}
        <form class="d-none d-md-block flex-grow-1 sz-topbar-search"
              action="{{ $user->isAdmin() ? route('admin.search') : route('opportunities.index') }}" method="GET">
            <label class="visually-hidden" for="sz-topbar-search">
                {{ $user->isAdmin() ? 'Search users, listings and applications' : 'Search scholarships' }}
            </label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-body border-end-0"><x-icon name="search" :size="16" /></span>
                <input type="search"
                       id="sz-topbar-search"
                       class="form-control border-start-0"
                       name="{{ $user->isAdmin() ? 'q' : 'keyword' }}"
                       value="{{ request('q') ?? request('keyword') }}"
                       placeholder="{{ $user->isAdmin() ? 'Search users, listings, applications' : 'Search scholarships' }}">
            </div>
        </form>

        <a class="btn border-0 d-md-none px-2"
           href="{{ $user->isAdmin() ? route('admin.search') : route('opportunities.index') }}"
           aria-label="{{ $user->isAdmin() ? 'Search' : 'Find scholarships' }}">
            <x-icon name="search" />
        </a>

        <div class="ms-auto d-flex align-items-center gap-1 gap-sm-2">

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

                {{-- Capped to the viewport so the panel cannot hang off the right edge of a phone. --}}
                <div class="dropdown-menu dropdown-menu-end p-0 shadow sz-notification-menu">
                    <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                        <span class="fw-semibold">Notifications</span>
                        @if(($unreadNotifications ?? 0) > 0)
                            <form method="POST" action="{{ route('notifications.readAll') }}" class="m-0">
                                @csrf
                                <button class="btn btn-sm btn-link p-0" type="submit">Mark all read</button>
                            </form>
                        @endif
                    </div>

                    <div class="list-group list-group-flush sz-notification-list">
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
                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
                    <x-avatar :user="$user" size="sm" />
                    <span class="d-none d-lg-inline small fw-semibold text-truncate" style="max-width: 10rem;">{{ $user->displayName() }}</span>
                </button>

                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li>
                        <span class="dropdown-header">
                            <span class="d-block fw-semibold text-body">{{ $user->displayName() }}</span>
                            <span class="d-block text-truncate">{{ $user->email }}</span>
                        </span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    @if($user->isApplicant())
                        <li><a class="dropdown-item" href="{{ route('applicant.profile') }}">My profile</a></li>
                    @endif
                    <li><a class="dropdown-item" href="{{ route('notifications.index') }}">Notifications</a></li>
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
