<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Dto\Api\AvailabilityData;
use App\Http\Dto\Api\BookingData;
use App\Http\Dto\Api\BookingRequestData;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\ConfirmBookingRequestRequest;
use App\Http\Requests\Booking\CreateBookingRequestRequest;
use App\Http\Resources\BookingRequestResource;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Service;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\IdempotencyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BookingService $bookings,
        private readonly AvailabilityService $availability,
        private readonly IdempotencyService $idempotency,
    ) {}

    /**
     * GET /businesses/{business}/services/{service}/availability
     */
    public function availability(Request $request, Business $business, Service $service): JsonResponse
    {
        $data = $request->validate([
            'business_location_id' => ['required', 'ulid', 'exists:business_locations,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'timezone' => ['nullable', 'timezone:all'],
            'days' => ['nullable', 'integer', 'between:1,14'],
        ]);

        $branch = BusinessLocation::query()
            ->where('business_id', $business->getKey())
            ->whereKey($data['business_location_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $people = (int) $request->query('people_count', 1);
        $days = (int) ($data['days'] ?? 1);
        $timezone = $data['timezone'] ?? $branch->timezone;

        $dates = collect(range(0, $days - 1))
            ->map(fn (int $i) => Carbon::parse($data['date'], $timezone)->startOfDay()->addDays($i));

        $availability = $dates->map(function (Carbon $date) use ($service, $branch, $people) {
            $slots = $this->availability->slotsFor($service, $branch, $date, $people);

            return [
                'date' => $date->toDateString(),
                'is_closed' => $slots->isEmpty() && $this->availability->isClosureDate($branch, $date),
                'slots' => $slots->values(),
            ];
        });

        $service->setAttribute('price', $service->prices()->where('is_current', true)->value('price_minor'));
        $service->setAttribute('currency', $service->prices()->where('is_current', true)->value('currency'));

        return $this->success((new AvailabilityData($business->getKey(), $service, $timezone, $availability))->toArray(), 'Availability loaded.');
    }

    /**
     * POST /bookings/requests
     */
    public function createRequest(CreateBookingRequestRequest $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();
        $hash = IdempotencyService::requestHash($payload + ['user' => $user->getKey()]);

        [$status, $data, $replayed] = $this->idempotency->run(
            $user->getKey(),
            'booking.request.create',
            $request->header('Idempotency-Key'),
            $hash,
            function () use ($request, $payload): array {
                $bookingRequest = app(BookingService::class)->createRequest($request->user(), $payload);

                return [
                    Response::HTTP_ACCEPTED,
                    array_merge((new BookingRequestData($bookingRequest->load(['service', 'business'])))->toArray(), ['poll_after' => 2]),
                ];
            },
        );

        return $this->success($data, $replayed ? 'Request replayed.' : 'Booking request sent.', Response::HTTP_ACCEPTED);
    }

    /**
     * GET /bookings/requests/{bookingRequest}
     */
    public function showRequest(Request $request, BookingRequest $bookingRequest): JsonResponse
    {
        abort_unless($bookingRequest->user_id === $request->user()->getKey(), 403);

        $bookingRequest->load(['service', 'business', 'times' => fn ($q) => $q->where('is_available', true)]);

        return $this->success((new BookingRequestData($bookingRequest))->toArray(), 'Booking request status loaded.');
    }

    /**
     * POST /bookings/requests/{bookingRequest}/confirm
     */
    public function confirmRequest(ConfirmBookingRequestRequest $request, BookingRequest $bookingRequest): JsonResponse
    {
        $booking = $this->bookings->confirmRequest(
            $request->user(),
            $bookingRequest,
            $request->validated('time_id'),
        );

        return $this->success((new BookingData($booking->load(['items', 'business'])))->toArray(), 'Booking created.', Response::HTTP_CREATED);
    }

    /**
     * GET /bookings — upcoming or past.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope', 'upcoming');
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = Booking::query()
            ->where('user_id', $request->user()->getKey())
            ->with(['items', 'business', 'businessLocation', 'payments'])
            ->when($scope === 'past', fn ($q) => $q->whereIn('status', ['completed', 'cancelled', 'expired', 'refunded'])->orderByDesc('starts_at'))
            ->when($scope !== 'past', fn ($q) => $q->whereIn('status', ['awaiting_payment', 'confirmed', 'in_progress'])->orderBy('starts_at'));

        return $this->paginated(BookingResource::collection($query->paginate($perPage)), 'Booking history loaded.');
    }

    /**
     * GET /bookings/{booking}
     */
    public function show(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->getKey(), 403);

        $booking->load(['items', 'business', 'businessLocation', 'payments', 'review', 'assignedStaff']);

        return $this->resource(BookingResource::make($booking));
    }

    /**
     * POST /bookings/{booking}/cancel
     */
    public function cancel(CancelBookingRequest $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->getKey(), 403);

        abort_unless(in_array($booking->status, [BookingStatus::AwaitingPayment, BookingStatus::Confirmed], true), 409, 'This booking can no longer be cancelled.');

        $updated = $this->bookings->cancel($booking, $request->user(), $request->validated('reason'));

        return $this->resource(BookingResource::make($updated->load(['items', 'business'])), 'Booking cancelled.');
    }
}
