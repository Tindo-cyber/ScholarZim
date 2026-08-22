@extends('layouts.app')

@section('title', 'Notifications')

@section('content')

    <x-page-header title="Notifications"
                   :subtitle="$unreadCount > 0 ? $unreadCount . ' unread' : 'You are all caught up.'">
        <x-slot:actions>
            @if($unreadCount > 0)
                <form method="POST" action="{{ route('notifications.readAll') }}" class="m-0">
                    @csrf
                    <button class="btn btn-outline-secondary" type="submit">Mark all as read</button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <ul class="nav nav-pills gap-2 mb-4">
        <li class="nav-item">
            <a class="nav-link @active(!$activeCategory)" href="{{ route('notifications.index') }}">All</a>
        </li>
        @foreach($categories as $category)
            <li class="nav-item">
                <a class="nav-link @active($activeCategory === $category)"
                   href="{{ route('notifications.index', ['category' => $category]) }}">{{ $category }}</a>
            </li>
        @endforeach
    </ul>

    @if($notifications->isEmpty())
        <div class="card">
            <x-empty-state title="No notifications"
                           message="Application updates, deadline reminders, and new matches all land here."
                           icon="bell" />
        </div>
    @else
        <div class="card">
            <ul class="list-group list-group-flush">
                @foreach($notifications as $notification)
                    <li class="list-group-item d-flex gap-3 align-items-start {{ $notification->is_read ? '' : 'bg-body-secondary' }}">
                        <span class="badge rounded-circle bg-{{ $notification->tone() }}-subtle text-{{ $notification->tone() }} p-2 flex-shrink-0">
                            <x-icon :name="$notification->icon()" :size="16" />
                        </span>

                        <div class="min-w-0 flex-grow-1">
                            <p class="mb-1">{{ $notification->message }}</p>
                            <span class="small text-secondary">
                                {{ $notification->category() }} &middot; {{ $notification->created_at?->diffForHumans() }}
                            </span>
                        </div>

                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                            @unless($notification->is_read)
                                <span class="badge bg-primary">New</span>
                            @endunless

                            @if($notification->link)
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="{{ route('notifications.open', $notification->notification_id) }}">Open</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-4">
            {{ $notifications->links() }}
        </div>
    @endif

@endsection
