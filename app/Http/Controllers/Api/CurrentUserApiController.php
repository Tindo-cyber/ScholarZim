<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use App\Support\RoleNames;
use Illuminate\Http\Request;

class CurrentUserApiController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->user_id,
            'name' => $user->displayName(),
            'email' => $user->email,
            'role' => $user->roleName(),
            'roleLabel' => RoleNames::displayLabel($user->roleName()),
            'accountStatus' => $user->account_status,
            'emailVerified' => (bool) $user->email_verified,
            'unreadNotifications' => $this->notificationService->unreadCount($user->user_id),
            'profileCompletion' => $user->applicantProfile?->completionPercentage(),
        ]);
    }

    /** Powers the bell dropdown without a full page load. */
    public function notifications(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'unread' => $this->notificationService->unreadCount($user->user_id),
            'items' => $this->notificationService->latestForUser($user->user_id, 8)->map(fn ($n) => [
                'id' => $n->notification_id,
                'message' => $n->message,
                'link' => $n->link,
                'icon' => $n->icon(),
                'tone' => $n->tone(),
                'isRead' => (bool) $n->is_read,
                'createdAt' => $n->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
