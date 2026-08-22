<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Support\NotificationPresentation;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        return view('notifications.list', [
            'notifications' => $this->notificationService->paginateForUser($user->user_id, $request->query('category')),
            'activeCategory' => $request->query('category'),
            'categories' => [
                NotificationPresentation::CATEGORY_APPLICATIONS,
                NotificationPresentation::CATEGORY_SCHOLARSHIPS,
                NotificationPresentation::CATEGORY_SYSTEM,
            ],
            'unreadCount' => $this->notificationService->unreadCount($user->user_id),
        ]);
    }

    /** Marks read, then forwards to whatever the notification pointed at. */
    public function open(Request $request, int $id)
    {
        $notification = $this->notificationService->markRead($request->user()->user_id, $id);

        abort_if($notification === null, 404);

        return redirect($notification->link ?: route('notifications.index'));
    }

    public function markAllRead(Request $request)
    {
        $count = $this->notificationService->markAllRead($request->user()->user_id);

        return back()->with('successMessage', $count . ' notification(s) marked as read.');
    }
}
