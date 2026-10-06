<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Dto\Api\NotificationData;
use App\Http\Dto\Api\NotificationListData;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $timezone = $request->query('timezone', $request->user()->timezone ?? 'UTC');

        $query = Notification::query()
            ->where('user_id', $request->user()->getKey())
            ->where(function ($q) {
                $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
            });

        // Date filters interpreted in the supplied timezone.
        if ($filter = $request->query('filter')) {
            $tz = new \DateTimeZone($timezone);

            $range = match ($filter) {
                'today' => [now($tz)->startOfDay(), now($tz)->endOfDay()],
                'yesterday' => [now($tz)->subDay()->startOfDay(), now($tz)->subDay()->endOfDay()],
                'tomorrow' => [now($tz)->addDay()->startOfDay(), now($tz)->addDay()->endOfDay()],
                default => null,
            };

            if ($range !== null) {
                [$from, $to] = $range;
                $query->whereBetween('created_at', [$from->utc(), $to->utc()]);
            }
        }

        $notifications = $query->orderByDesc('created_at')->paginate($perPage);

        return $this->success((new NotificationListData(
            $notifications->getCollection()->map(fn (Notification $notification) => (new NotificationData($notification))->toArray())->values()->all(),
            $notifications->currentPage(), $notifications->perPage(), $notifications->total(), $notifications->hasMorePages(),
        ))->toArray(), 'Notifications loaded.');
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->getKey(), 403);

        $read = $request->boolean('read', true);

        $notification->forceFill(['read_at' => $read ? now() : null])->save();

        return $this->success((new NotificationData($notification))->toArray(), 'Notification updated.');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success(['count' => $this->notifications->unreadCount($request->user())]);
    }
}
