<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationPresentation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(private readonly EmailService $emailService)
    {
    }

    /**
     * Writes the in-app notification, then sends the email only if the user has
     * kept the matching category preference on. The in-app row is written either
     * way — preferences gate email, not the notification centre.
     */
    public function notifyUser(
        User $user,
        string $type,
        string $message,
        ?string $link = null,
        ?int $relatedId = null
    ): ?Notification {
        try {
            $notification = Notification::create([
                'user_id' => $user->user_id,
                'type' => $type,
                'message' => $message,
                'link' => $link,
                'related_id' => $relatedId,
                'is_read' => false,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Notification write failed', [
                'user' => $user->email,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (NotificationPresentation::emailAllowed($user, $type)) {
            $this->emailService->sendNotification($user, $type, $message, $link);
        }

        return $notification;
    }

    /** @param  iterable<User>  $users */
    public function notifyMany(iterable $users, string $type, string $message, ?string $link = null, ?int $relatedId = null): void
    {
        foreach ($users as $user) {
            $this->notifyUser($user, $type, $message, $link, $relatedId);
        }
    }

    /**
     * Whether this user already has a notification of this type for this record.
     * The reminder jobs use it to stay idempotent across daily runs.
     */
    public function hasNotification(User $user, string $type, ?int $relatedId): bool
    {
        if ($relatedId === null) {
            return false;
        }

        return Notification::where('user_id', $user->user_id)
            ->where('type', $type)
            ->where('related_id', $relatedId)
            ->exists();
    }

    public function unreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)->where('is_read', false)->count();
    }

    public function latestForUser(int $userId, int $limit = 8)
    {
        return Notification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('notification_id')
            ->limit($limit)
            ->get();
    }

    public function paginateForUser(int $userId, ?string $category = null, int $perPage = 20)
    {
        $query = Notification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('notification_id');

        $page = $query->paginate($perPage)->withQueryString();

        if (filled($category)) {
            // Category is derived from the type in PHP rather than stored, so it is
            // filtered after fetching the page.
            $page->setCollection(
                $page->getCollection()->filter(
                    static fn (Notification $n) => NotificationPresentation::category($n->type) === $category
                )->values()
            );
        }

        return $page;
    }

    public function markRead(int $userId, int $notificationId): ?Notification
    {
        $notification = Notification::where('user_id', $userId)
            ->where('notification_id', $notificationId)
            ->first();

        if ($notification && ! $notification->is_read) {
            $notification->update(['is_read' => true]);
        }

        return $notification;
    }

    public function markAllRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }
}
