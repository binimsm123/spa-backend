<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BusinessUser;
use App\Services\BookingService;
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
            ->with(['items', 'user', 'business', 'payments', 'assignedStaff'])
            ->when($request->query('q'), function ($q, $search): void {
                $q->whereKey($search)->orWhereHas('user', fn ($u) => $u->where('mobile', 'like', '%'.$search.'%'))
                    ->orWhereHas('business', fn ($b) => $b->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('items', fn ($i) => $i->where('service_name_snapshot', 'like', '%'.$search.'%'));
            })
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('business_id'), fn ($q, $id) => $q->where('business_id', $id))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('appointment_date', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('appointment_date', '<=', $to))
            ->when($request->query('staff_user_id'), fn ($q, $id) => $q->where('assigned_staff_user_id', $id))
            ->orderByDesc('starts_at');

        return $this->paginated(BookingResource::collection($query->paginate($perPage)));
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $booking->load(['items', 'user', 'business', 'payments', 'assignedStaff', 'statusHistory.changedBy', 'review', 'offerRedemption']);

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
        $data = $request->validate(['staff_user_id' => ['required', 'ulid', 'exists:users,id']]);

        $staffId = $data['staff_user_id'];
        $businessId = $booking->business_id;

        // The staff member must belong to the booking's business.
        $isMember = BusinessUser::query()
            ->where('business_id', $businessId)
            ->where('user_id', $staffId)
            ->where('is_active', true)
            ->exists();

        abort_unless($isMember, 422, 'The assigned staff member must belong to this business.');

        // Prevent overlapping appointments for the same staff member.
        $conflict = Booking::query()
            ->where('assigned_staff_user_id', $staffId)
            ->whereIn('status', ['confirmed', 'in_progress'])
            ->where('starts_at', '<', $booking->ends_at)
            ->where('ends_at', '>', $booking->starts_at)
            ->whereKeyNot($booking->getKey())
            ->exists();

        abort_if($conflict, 409, 'The staff member already has an appointment in that time window.');

        $before = $booking->assigned_staff_user_id;
        $booking->forceFill(['assigned_staff_user_id' => $staffId])->save();

        AuditLogger::record($request->user(), 'booking.staff_reassigned', 'booking', $booking->getKey(), ['staff' => $before], ['staff' => $staffId]);

        return $this->resource(BookingResource::make($booking->refresh()->load('assignedStaff')), 'Staff reassigned.');
    }
}
