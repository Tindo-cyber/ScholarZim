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

        // Normalised before it reaches the query, so an unrecognised value in the
        // URL selects nothing rather than being passed through as a filter.
        $category = NotificationPresentation::isKnownCategory($request->query('category'))
            ? $request->query('category')
            : null;

        return view('notifications.list', [
            'notifications' => $this->notificationService->paginateForUser($user->user_id, $category),
            'activeCategory' => $category,
            'categories' => NotificationPresentation::CATEGORIES,
            // The bell counts everything unread; the figure beside a chosen
            // category counts only that category, so the two numbers on the page
            // are answering the question each of them appears to be answering.
            'unreadCount' => $this->notificationService->unreadCount($user->user_id),
            'categoryUnreadCount' => $this->notificationService->unreadCountForUser($user->user_id, $category),
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
