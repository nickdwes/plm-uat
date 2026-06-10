<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get current user's unread notifications
     */
    public function unread(Request $request): JsonResponse
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->byOrganization(app('organizationId'))
            ->unread()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($notifications);
    }

    /**
     * Get all notifications for current user
     */
    public function all(Request $request): JsonResponse
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->byOrganization(app('organizationId'))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($notifications);
    }

    /**
     * Get unread count
     */
    public function unreadCount(): JsonResponse
    {
        $count = Notification::where('user_id', auth()->id())
            ->byOrganization(app('organizationId'))
            ->unread()
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(string $id): JsonResponse
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', auth()->id())
            ->byOrganization(app('organizationId'))
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json(['status' => 'success', 'message' => 'Notification marked as read']);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(): JsonResponse
    {
        Notification::where('user_id', auth()->id())
            ->byOrganization(app('organizationId'))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['status' => 'success', 'message' => 'All notifications marked as read']);
    }

    /**
     * Delete notification
     */
    public function delete(string $id): JsonResponse
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', auth()->id())
            ->byOrganization(app('organizationId'))
            ->firstOrFail();

        $notification->delete();

        return response()->json(['status' => 'success', 'message' => 'Notification deleted']);
    }
}
