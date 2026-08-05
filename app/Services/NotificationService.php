<?php

namespace App\Services;

use App\Models\Notification;

/**
 * Manages in-app notifications for users.
 */
class NotificationService
{
    /**
     * Send a notification to a user.
     */
    public function send(int $userId, string $title, string $body): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'title'   => $title,
            'body'    => $body,
        ]);
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(Notification $notification): bool
    {
        return $notification->update(['read_at' => now()]);
    }

    /**
     * Mark all notifications for a user as read.
     */
    public function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * List unread notifications for a user.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Notification>
     */
    public function unreadFor(int $userId)
    {
        return Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->latest()
            ->get();
    }

    /**
     * List all notifications for a user.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Notification>
     */
    public function allFor(int $userId)
    {
        return Notification::where('user_id', $userId)
            ->latest()
            ->get();
    }
}
