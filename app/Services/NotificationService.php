<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\NotificationPresentation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly AuditService $auditService,
    ) {
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
            // Swallowed on purpose, but never quietly. These calls run after the
            // transaction they belong to has committed, so the decision they
            // announce has already happened and cannot be undone by a failure to
            // announce it - throwing here would turn a lost notification into a
            // lost application. What must not happen is losing it silently: a
            // student who was never told their award was approved needs to leave
            // a trace somewhere durable, not one line in a log file that rotates.
            Log::warning('Notification write failed', [
                'user' => $user->email,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            $this->auditService->log(
                $user->email,
                AuditAction::NOTIFICATION_DELIVERY_FAILED,
                'USER',
                $user->user_id,
                'Could not record a ' . $type . ' notification: ' . $e->getMessage()
            );

            return null;
        }

        if (NotificationPresentation::emailAllowed($user, $type)) {
            $this->emailService->sendNotification($user, $type, $message, $link);
        }

        return $notification;
    }

    /**
     * The same, but at most once per (user, type, record).
     *
     * For events that can legitimately be replayed - a queued job retried after
     * a timeout, a scheduled sweep running twice in a day - where a second
     * notification would be a duplicate rather than news.
     *
     * Deliberately opt-in rather than the default. Some notifications are meant
     * to repeat: a provider asking a second time for a document is a new request
     * the applicant has to see, and Stage 2 established re-asking as a supported
     * workflow. Making notifyUser() idempotent everywhere would silently swallow
     * exactly those.
     */
    public function notifyOnce(
        User $user,
        string $type,
        string $message,
        ?string $link = null,
        ?int $relatedId = null
    ): ?Notification {
        if ($this->hasNotification($user, $type, $relatedId)) {
            return null;
        }

        return $this->notifyUser($user, $type, $message, $link, $relatedId);
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

    /**
     * A page of this user's notifications, optionally narrowed to one category.
     *
     * The filter is applied in the query, before the page is cut. Doing it the
     * other way round - fetch twenty rows, then discard the ones in the wrong
     * category - is what this used to do, and it broke the page in three ways at
     * once: the row count shown was whatever survived the discard, the total and
     * last-page numbers described the unfiltered set, and a page whose twenty
     * rows were all the wrong category rendered empty while later pages still
     * had matches. A reader who clicked "next" past an empty page found more
     * results, which is the sort of thing people report as "notifications are
     * missing".
     *
     * Category is derived from the type rather than stored, so the filter is a
     * whereIn over the types in that category. An unrecognised category is
     * ignored rather than matched against nothing, so a stale bookmark shows the
     * full list instead of a convincing empty one.
     */
    public function paginateForUser(int $userId, ?string $category = null, int $perPage = 20)
    {
        return $this->scopedQuery($userId, $category)
            ->orderByDesc('created_at')
            ->orderByDesc('notification_id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** How many the user has in total, honouring the same category filter. */
    public function countForUser(int $userId, ?string $category = null): int
    {
        return $this->scopedQuery($userId, $category)->count();
    }

    /** Unread count, honouring the same category filter. */
    public function unreadCountForUser(int $userId, ?string $category = null): int
    {
        return $this->scopedQuery($userId, $category)->where('is_read', false)->count();
    }

    /**
     * One place that turns "this user, maybe this category" into a query, so the
     * list, the totals and the unread badge can never disagree about what the
     * filter means.
     */
    private function scopedQuery(int $userId, ?string $category = null)
    {
        $query = Notification::where('user_id', $userId);

        if (NotificationPresentation::isKnownCategory($category)) {
            $query->whereIn('type', NotificationPresentation::typesInCategory($category));
        }

        return $query;
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
