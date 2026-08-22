@php
    $user = auth()->user();
    $role = $user->roleName();
@endphp

<aside class="sz-sidebar border-end bg-body" id="szSidebar">
    <div class="d-flex flex-column h-100">

        <div class="px-3 py-3 border-bottom d-flex align-items-center justify-content-between">
            <a class="d-flex align-items-center gap-2 fw-bold text-decoration-none" href="{{ route('dashboard') }}">
                <x-brand-mark />
                <span>Scholar<span class="text-primary">Zim</span></span>
            </a>
            <button class="btn btn-sm d-xl-none border-0" type="button"
                    data-bs-dismiss="offcanvas" data-bs-target="#szSidebar" aria-label="Close menu">&times;</button>
        </div>

        <nav class="flex-grow-1 overflow-auto px-2 py-3">
            <ul class="nav nav-pills flex-column gap-1">

                @if($role === \App\Support\RoleNames::APPLICANT)
                    <x-nav-item :href="route('applicant.dashboard')" icon="grid" :active="request()->routeIs('applicant.dashboard')">Dashboard</x-nav-item>
                    <x-nav-item :href="route('applicant.recommendations')" icon="stars" :active="request()->routeIs('applicant.recommendations')">My matches</x-nav-item>
                    <x-nav-item :href="route('opportunities.index')" icon="search" :active="request()->routeIs('opportunities.index')">Browse scholarships</x-nav-item>
                    <x-nav-item :href="route('applications.mine')" icon="file-text" :active="request()->routeIs('applications.mine')">My applications</x-nav-item>
                    <x-nav-item :href="route('applicant.saved')" icon="bookmark" :active="request()->routeIs('applicant.saved')">Saved</x-nav-item>
                    <x-nav-item :href="route('applicant.profile')" icon="person" :active="request()->routeIs('applicant.profile')">My profile</x-nav-item>
                @endif

                @if($role === \App\Support\RoleNames::PROVIDER)
                    <x-nav-item :href="route('provider.dashboard')" icon="grid" :active="request()->routeIs('provider.dashboard')">Dashboard</x-nav-item>
                    <x-nav-item :href="route('opportunities.create')" icon="plus" :active="request()->routeIs('opportunities.create')">Post a scholarship</x-nav-item>
                    <x-nav-item :href="route('provider.applications')" icon="inbox" :active="request()->routeIs('provider.applications*')">Applications</x-nav-item>
                    <x-nav-item :href="route('opportunities.index')" icon="search" :active="request()->routeIs('opportunities.index')">All scholarships</x-nav-item>
                @endif

                @if($role === \App\Support\RoleNames::ADMIN)
                    <x-nav-item :href="route('admin.dashboard')" icon="grid" :active="request()->routeIs('admin.dashboard')">Dashboard</x-nav-item>
                    <x-nav-item :href="route('admin.users.index')" icon="people" :active="request()->routeIs('admin.users.*')">Users</x-nav-item>
                    <x-nav-item :href="route('admin.analytics')" icon="chart" :active="request()->routeIs('admin.analytics')">Analytics</x-nav-item>
                    <x-nav-item :href="route('admin.audit')" icon="shield" :active="request()->routeIs('admin.audit')">Audit log</x-nav-item>
                    <x-nav-item :href="route('admin.reports')" icon="download" :active="request()->routeIs('admin.reports')">Reports</x-nav-item>
                    <x-nav-item :href="route('admin.search')" icon="search" :active="request()->routeIs('admin.search')">Search</x-nav-item>
                @endif

                <li class="nav-item mt-3 mb-1 px-3">
                    <span class="text-uppercase small fw-semibold text-secondary">Account</span>
                </li>
                <x-nav-item :href="route('notifications.index')" icon="bell" :active="request()->routeIs('notifications.*')">
                    Notifications
                    @if(($unreadNotifications ?? 0) > 0)
                        <span class="badge rounded-pill bg-danger ms-auto">{{ $unreadNotifications }}</span>
                    @endif
                </x-nav-item>
                <x-nav-item :href="route('account.security')" icon="lock" :active="request()->routeIs('account.*')">Security &amp; privacy</x-nav-item>
            </ul>
        </nav>

        <div class="border-top p-3">
            <div class="d-flex align-items-center gap-2">
                <x-avatar :user="$user" />
                <div class="min-w-0 flex-grow-1">
                    <div class="fw-semibold text-truncate">{{ $user->displayName() }}</div>
                    <div class="small text-secondary">{{ \App\Support\RoleNames::displayLabel($role) }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary" type="submit" title="Sign out">Exit</button>
                </form>
            </div>
        </div>
    </div>
</aside>
