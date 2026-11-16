<?php

namespace App\Http\Controllers\Api\Business;

use App\Enums\BookingStatus;
use App\Enums\BusinessUserRole;
use App\Http\Controllers\Controller;
use App\Http\Dto\Api\BookingData;
use App\Http\Requests\Booking\UpdateBookingStatusRequest;
use App\Http\Resources\BookingRequestResource;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Business;
use App\Services\BookingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessBookingController extends Controller
{
    use ApiResponse;

    /**
     * GET /business/{business}/bookings/requests
     */
    public function requests(Request $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business);

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = BookingRequest::query()
            ->where('business_id', $business->getKey())
            ->with(['service', 'servicePrice', 'user', 'booking'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at');

        return $this->paginated(BookingRequestResource::collection($query->paginate($perPage)));
    }

    /**
     * POST /business/{business}/bookings/requests/{bookingRequest}/reject
     */
    public function reject(Request $request, Business $business, BookingRequest $bookingRequest): JsonResponse
    {
        $this->assertMember($request, $business);
        abort_unless($bookingRequest->business_id === $business->getKey(), 404);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $updated = app(BookingService::class)->rejectRequest($request->user(), $bookingRequest, $data['reason'] ?? null);

        return $this->resource(BookingRequestResource::make($updated->load(['service', 'servicePrice'])), 'Booking request rejected.');
    }

    public function approve(Request $request, Business $business, BookingRequest $bookingRequest): JsonResponse
    {
        $this->assertMember($request, $business);
        abort_unless($bookingRequest->business_id === $business->getKey(), 404);
        $booking = app(BookingService::class)->approveRequest($request->user(), $bookingRequest);

        return $this->created((new BookingData($booking->load(['items', 'business.locations'])))->toArray(), 'Booking request approved.');
    }

    /**
     * GET /business/{business}/bookings
     */
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->assertMember($request, $business);

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = Booking::query()
            ->where('business_id', $business->getKey())
            ->with(['items', 'user', 'business.locations', 'payments', 'assignedStaff', 'assignedStaffMembers'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('date'), fn ($q, $date) => $q->whereDate('appointment_date', $date))
            ->when($request->query('payment_status'), fn ($q, $ps) => $q->whereHas('payments', fn ($p) => $p->where('status', $ps)))
            ->orderBy('starts_at');

        return $this->paginated(BookingResource::collection($query->paginate($perPage)));
    }

    /**
     * PATCH /business/{business}/bookings/{booking}/status
     */
    public function updateStatus(UpdateBookingStatusRequest $request, Business $business, Booking $booking): JsonResponse
    {
        $this->assertMember($request, $business);
        abort_unless($booking->business_id === $business->getKey(), 404);

        $service = app(BookingService::class);
        $status = BookingStatus::from($request->validated('status'));

        $updated = $service->transitionStatus($booking, $status, $request->user(), $request->validated('note'));

        return $this->resource(BookingResource::make($updated->load(['items', 'user'])), 'Booking updated.');
    }

    private function assertMember(Request $request, Business $business, ?array $roles = null): void
    {
        $user = $request->user();

        abort_unless($user->belongsToBusiness($business), 403, 'You do not manage this business.');

        if ($roles !== null && ! $user->hasBusinessRole($business, ...array_map(
            fn (string $r) => BusinessUserRole::from($r),
            $roles,
        ))) {
            abort(403, 'Your role cannot perform this action.');
        }
    }
}
