<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;

class NotificationService
{
    /**
     * Create and (when not scheduled) deliver an in-app notification.
     */
    public function notify(User $user, NotificationType $type, string $title, ?string $body = null, array $data = [], ?Carbon $scheduledFor = null): Notification
    {
        $notification = $user->notifications()->create([
            'type' => $type->value,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'scheduled_for' => $scheduledFor,
            'read_at' => null,
        ]);

        return $notification;
    }

    /**
     * Unread count, ignoring notifications scheduled for the future.
     */
    public function unreadCount(User $user): int
    {
        return $user->notifications()
            ->whereNull('read_at')
            ->where(function ($query): void {
                $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
            })
            ->count();
    }
}
