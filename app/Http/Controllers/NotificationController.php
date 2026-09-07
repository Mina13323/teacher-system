<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\TeacherMessageNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notification inbox for any authenticated user. Notifications are stored
 * via Laravel's database channel and never include secrets or answer keys.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate($this->perPage($request));

        return $this->success($notifications, 'Notifications retrieved.');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()->unreadNotifications()->count();

        return $this->success(['unread_count' => $count], 'Unread count retrieved.');
    }

    public function read(Request $request, DatabaseNotification $notification): JsonResponse
    {
        abort_unless((string) $notification->notifiable_id === (string) $request->user()->getKey(), 404, 'Notification not found.');

        $notification->markAsRead();

        return $this->success(null, 'Notification marked as read.');
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->success(null, 'All notifications marked as read.');
    }

    /**
     * Send a teacher-to-student message notification.
     */
    public function sendMessage(Request $request, User $student): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $this->authorize('manage', $student);

        $student->notify(new TeacherMessageNotification($request->user(), $request->string('message')->toString()));

        return $this->success(null, 'Message sent.');
    }

    private function perPage(Request $request): int
    {
        return $request->integer('per_page', 20) > 0
            ? min(100, $request->integer('per_page', 20))
            : 20;
    }
}
