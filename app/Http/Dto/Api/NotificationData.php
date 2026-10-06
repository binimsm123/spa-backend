<?php

namespace App\Http\Dto\Api;

use App\Models\Notification;

final readonly class NotificationData
{
    public function __construct(private Notification $notification) {}

    public function toArray(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'read' => $this->notification->read_at !== null,
            'read_at' => $this->notification->read_at?->toIso8601String(),
            'display_time' => $this->notification->created_at?->format('g:i A'),
            'created_at' => $this->notification->created_at?->toIso8601String(),
            'scheduled_for' => $this->notification->scheduled_for?->toIso8601String(),
            'data' => $this->notification->data,
        ];
    }
}
