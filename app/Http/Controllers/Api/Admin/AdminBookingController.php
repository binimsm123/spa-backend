<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\BookingStaffService;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BookingService $bookings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = Booking::query()
            ->with(['items', 'user', 'business', 'payments', 'assignedStaff', 'assignedStaffMembers'])
            ->when($request->query('q'), function ($q, $search): void {
                $q->whereKey($search)->orWhereHas('user', fn ($u) => $u->where('mobile', 'like', '%'.$search.'%'))
                    ->orWhereHas('business', fn ($b) => $b->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('items', fn ($i) => $i->where('service_name_snapshot', 'like', '%'.$search.'%'));
            })
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('business_id'), fn ($q, $id) => $q->where('business_id', $id))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('appointment_date', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('appointment_date', '<=', $to))
            ->when($request->query('staff_user_id'), fn ($q, $id) => $q->where(fn ($staff) => $staff
                ->where('assigned_staff_user_id', $id)->orWhereHas('assignedStaffMembers', fn ($members) => $members->whereKey($id))))
            ->orderByDesc('starts_at');

        return $this->paginated(BookingResource::collection($query->paginate($perPage)));
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $booking->load(['items', 'user', 'business', 'payments', 'assignedStaff', 'assignedStaffMembers', 'statusHistory.changedBy', 'review', 'offerRedemption']);

        $data = BookingResource::make($booking)->resolve();
        $data['status_history'] = $booking->statusHistory->map(fn ($h) => [
            'old_status' => $h->old_status,
            'new_status' => $h->new_status,
            'note' => $h->note,
            'changed_by' => $h->changedBy?->display_name,
            'created_at' => $h->created_at?->toIso8601String(),
        ]);

        return $this->success($data);
    }

    public function updateStatus(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:confirmed,in_progress,completed,cancelled,refunded'],
            'note' => ['required_if:status,cancelled', 'nullable', 'string', 'max:255'],
        ]);

        $before = $booking->status->value;
        $updated = $this->bookings->transitionStatus($booking, BookingStatus::from($data['status']), $request->user(), $data['note'] ?? null);

        AuditLogger::record($request->user(), 'booking.status_changed', 'booking', $booking->getKey(), ['status' => $before], ['status' => $updated->status->value], $data['note'] ?? null);

        return $this->resource(BookingResource::make($updated->load(['items', 'user'])), 'Booking updated.');
    }

    public function reassignStaff(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate([
            'staff_user_id' => ['required_without:staff_user_ids', 'ulid'],
            'staff_user_ids' => ['required_without:staff_user_id', 'array', 'list', 'min:1', 'max:50'],
            'staff_user_ids.*' => ['required', 'ulid', 'distinct'],
        ]);
        $before = $booking->assigned_staff_user_id;
        $booking = app(BookingStaffService::class)->assign($booking, $data['staff_user_ids'] ?? [$data['staff_user_id']]);
        AuditLogger::record($request->user(), 'booking.staff_reassigned', 'booking', $booking->getKey(), ['staff' => $before], ['staff' => $booking->assignedStaffMembers->pluck('id')->all()]);

        return $this->resource(BookingResource::make($booking), 'Staff reassigned.');
    }
}
