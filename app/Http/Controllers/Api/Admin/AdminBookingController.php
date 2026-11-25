<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BookingRequestStatus;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingRequestResource;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Business;
use App\Services\BookingService;
use App\Services\BookingStaffService;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

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
                $q->where(fn ($matches) => $matches
                    ->whereKey($search)
                    ->orWhereHas('user', fn ($u) => $u->where('mobile', 'like', '%'.$search.'%'))
                    ->orWhereHas('business', fn ($b) => $b->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('items', fn ($i) => $i->where('service_name_snapshot', 'like', '%'.$search.'%')));
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

    public function businesses(Request $request): JsonResponse
    {
        $search = trim($request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:120']])['q'] ?? '');

        return $this->paginated(
            JsonResource::collection(
                Business::query()->select(['id', 'name'])
                    ->when($search !== '', fn ($query) => $query->whereLike('name', '%'.$search.'%'))
                    ->orderBy('name')->orderBy('id')->paginate(100)
            )
        );
    }

    public function requests(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(BookingRequestStatus::class)]]);
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = BookingRequest::query()
            ->with(['business', 'user', 'service', 'servicePrice'])
            ->when($request->query('q'), fn ($q, $search) => $q->where(fn ($matches) => $matches
                ->whereKey($search)
                ->orWhereHas('user', fn ($user) => $user->where('mobile', 'like', '%'.$search.'%'))
                ->orWhereHas('business', fn ($business) => $business->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('service', fn ($service) => $service->where('name', 'like', '%'.$search.'%'))))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('business_id'), fn ($q, $id) => $q->where('business_id', $id))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('requested_date', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('requested_date', '<=', $to))
            ->orderByDesc('created_at');

        return $this->paginated(BookingRequestResource::collection($query->paginate($perPage)));
    }

    public function requestAvailability(BookingRequest $bookingRequest): JsonResponse
    {
        return $this->success($this->bookings->previewRequestApproval($bookingRequest));
    }

    public function reviewRequest(Request $request, BookingRequest $bookingRequest): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        $reason = $data['reason'] ?? null;

        if ($data['action'] === 'approve') {
            $booking = $this->bookings->approveRequest($actor, $bookingRequest);
            AuditLogger::record($actor, 'booking_request.approved', 'booking_request', $bookingRequest->id);

            return $this->created(BookingResource::make($booking)->resolve(), 'Booking request approved.');
        }

        $updated = $this->bookings->rejectRequest($actor, $bookingRequest, $reason);
        AuditLogger::record($actor, 'booking_request.rejected', 'booking_request', $bookingRequest->id, reason: $reason);

        return $this->resource(BookingRequestResource::make($updated), 'Booking request rejected.');
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
