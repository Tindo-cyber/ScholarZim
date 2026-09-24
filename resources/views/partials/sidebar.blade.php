@php
    $user = auth()->user();
    $role = $user->roleName();
@endphp

{{--
    One sidebar for all three roles, grouped by what the reader is trying to do
    rather than as one flat list of everything they are allowed to reach.

    Every destination below is a route that exists. Where a section the brief
    asked for has no page behind it - a provider scholarship index, an admin
    providers/scholarships/applications index, admin settings - the item is
    absent rather than pointed at a route invented to satisfy the grouping.
    Three items are fragments of pages that do exist: #documents on the
    applicant profile, #listings on the provider dashboard, and
    #provider-verification / #scholarship-moderation on the admin dashboard.
    Those are why the fragment-aware active handling in
    resources/js/navigation.js exists.

    tabindex="-1" is here for the drawer: below xl this element is opened by
    Bootstrap's Offcanvas, which moves focus into it and traps it there, and an
    element that cannot take focus programmatically would leave focus behind on
    the page underneath.
--}}
<aside class="sz-sidebar border-end bg-body" id="szSidebar" tabindex="-1" aria-label="Main navigation">
    <div class="d-flex flex-column h-100">

        <div class="sz-sidebar-brand px-3 border-bottom d-flex align-items-center justify-content-between gap-2">
            <a class="d-flex align-items-center gap-2 fw-bold text-decoration-none" href="{{ route('dashboard') }}">
                <x-brand-mark />
                <span>Scholar<span class="text-primary">Zim</span></span>
            </a>
            <button class="btn-close d-xl-none" type="button"
                    data-bs-dismiss="offcanvas" data-bs-target="#szSidebar" aria-label="Close menu"></button>
        </div>

        <nav class="flex-grow-1 overflow-auto px-2 py-3">
            <ul class="nav nav-pills flex-column gap-1">

                @if($role === \App\Support\RoleNames::APPLICANT)
                    <x-nav-section label="Overview">
                        <x-nav-item :href="route('applicant.dashboard')" icon="grid"
                                    :active="request()->routeIs('applicant.dashboard')">Dashboard</x-nav-item>
                        <x-nav-item :href="route('applicant.recommendations')" icon="stars"
                                    :active="request()->routeIs('applicant.recommendations')">My matches</x-nav-item>
                    </x-nav-section>

                    <x-nav-section label="Discover">
                        <x-nav-item :href="route('opportunities.index')" icon="search"
                                    :active="request()->routeIs('opportunities.index')">Find scholarships</x-nav-item>
                        <x-nav-item :href="route('applicant.saved')" icon="bookmark"
                                    :active="request()->routeIs('applicant.saved')">Saved scholarships</x-nav-item>
                    </x-nav-section>

                    <x-nav-section label="Applications">
                        <x-nav-item :href="route('applications.mine')" icon="file-text"
                                    :active="request()->routeIs('applications.mine')">My applications</x-nav-item>
                    </x-nav-section>

                    <x-nav-section label="Profile">
                        <x-nav-item :href="route('applicant.profile')" icon="person"
                                    :active="request()->routeIs('applicant.profile')">My profile</x-nav-item>
                        {{-- Never server-active: it is #documents on the profile route, and a
                             fragment is never sent with the request. navigation.js resolves it. --}}
                        <x-nav-item :href="route('applicant.profile') . '#documents'" icon="upload"
                                    :active="false">Documents</x-nav-item>
                    </x-nav-section>
                @endif

                @if($role === \App\Support\RoleNames::PROVIDER)
                    <x-nav-section label="Overview">
                        <x-nav-item :href="route('provider.dashboard')" icon="grid"
                                    :active="request()->routeIs('provider.dashboard')">Dashboard</x-nav-item>
                    </x-nav-section>

                    <x-nav-section label="Scholarships">
                        <x-nav-item :href="route('opportunities.create')" icon="plus"
                                    :active="request()->routeIs('opportunities.create')">Post a scholarship</x-nav-item>
                        {{-- A provider's own listings are the "My listings" card on their
                             dashboard; there is no separate index route to point at. --}}
                        <x-nav-item :href="route('provider.dashboard') . '#listings'" icon="file-text"
                                    :active="false">My listings</x-nav-item>
                        <x-nav-item :href="route('opportunities.index')" icon="search"
                                    :active="request()->routeIs('opportunities.index')">All scholarships</x-nav-item>
                    </x-nav-section>

                    <x-nav-section label="Applications">
                        <x-nav-item :href="route('provider.applications')" icon="inbox"
                                    :active="request()->routeIs('provider.applications*')">Applications</x-nav-item>
                    </x-nav-section>

                    <x-nav-section label="Insights">
                        <x-nav-item :href="route('provider.analytics')" icon="trend"
                                    :active="request()->routeIs('provider.analytics')">Analytics</x-nav-item>
                    </x-nav-section>
                @endif

                @if($role === \App\Support\RoleNames::ADMIN)
                    <x-nav-section label="Overview">
                        <x-nav-item :href="route('admin.dashboard')" icon="grid"
                                    :active="request()->routeIs('admin.dashboard')">Dashboard</x-nav-item>
                    </x-nav-section>

                    <x-nav-section label="Management">
                        <x-nav-item :href="route('admin.users.index')" icon="people"
                                    :active="request()->routeIs('admin.users.*')">Users</x-nav-item>
                        <x-nav-item :href="route('admin.search')" icon="search"
                                    :active="request()->routeIs('admin.search')">Search</x-nav-item>
                    </x-nav-section>

                    {{-- Both are sections of the admin dashboard rather than pages of their
                         own; the fragments are real ids on that view. --}}
                    <x-nav-section label="Review">
                        <x-nav-item :href="route('admin.dashboard') . '#provider-verification'" icon="shield-check"
                                    :active="false">Approval &amp; verification</x-nav-item>
                        <x-nav-item :href="route('admin.dashboard') . '#scholarship-moderation'" icon="check-circle"
                                    :active="false">Moderation</x-nav-item>
                    </x-nav-section>

                    <x-nav-section label="Insights">
                        <x-nav-item :href="route('admin.analytics')" icon="chart"
                                    :active="request()->routeIs('admin.analytics')">Analytics</x-nav-item>
                        <x-nav-item :href="route('admin.reports')" icon="download"
                                    :active="request()->routeIs('admin.reports')">Reports</x-nav-item>
                    </x-nav-section>

                    <x-nav-section label="System">
                        <x-nav-item :href="route('admin.scholarfit')" icon="stars"
                                    :active="request()->routeIs('admin.scholarfit')">ScholarFit</x-nav-item>
                        <x-nav-item :href="route('admin.audit')" icon="shield"
                                    :active="request()->routeIs('admin.audit')">Audit log</x-nav-item>
                    </x-nav-section>
                @endif

                <x-nav-section label="Account">
                    <x-nav-item :href="route('notifications.index')" icon="bell"
                                :active="request()->routeIs('notifications.*')">
                        Notifications
                        @if(($unreadNotifications ?? 0) > 0)
                            <span class="badge rounded-pill bg-danger ms-auto">{{ $unreadNotifications }}</span>
                        @endif
                    </x-nav-item>
                    <x-nav-item :href="route('account.security')" icon="lock"
                                :active="request()->routeIs('account.*')">Security &amp; privacy</x-nav-item>
                </x-nav-section>

                {{-- Stays hidden unless the browser offers an install; see components/install-app. --}}
                <li class="nav-item mt-3 px-2">
                    <x-install-app class="btn btn-sm btn-outline-primary w-100 d-flex align-items-center justify-content-center gap-2" />
                </li>
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
