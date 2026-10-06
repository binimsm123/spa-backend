<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = Notification::query()
            ->with('user')
            ->when($request->query('unread') === 'true', fn ($q) => $q->whereNull('read_at'))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->orderByDesc('created_at');

        return $this->paginated(NotificationResource::collection($query->paginate($perPage)));
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $notification->forceFill(['read_at' => now()])->save();

        return $this->resource(NotificationResource::make($notification->refresh()), 'Notification marked as read.');
    }

    /**
     * Create a platform announcement delivered to all active customers.
     */
    public function announce(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:2000'],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
        ]);

        $scheduledFor = isset($data['scheduled_for']) ? \Illuminate\Support\Carbon::parse($data['scheduled_for']) : null;

        $targets = User::query()->where('is_active', true)->whereHas('roles', fn ($r) => $r->where('name', 'customer'))->get();

        foreach ($targets as $user) {
            $this->notifications->notify(
                $user,
                \App\Enums\NotificationType::Announcement,
                $data['title'],
                $data['body'],
                [],
                $scheduledFor,
            );
        }

        return $this->success([
            'recipients' => $targets->count(),
            'scheduled_for' => $scheduledFor?->toIso8601String(),
        ], 'Announcement queued.', 201);
    }
}
