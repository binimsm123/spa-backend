<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Dto\Api\AvailabilityData;
use App\Http\Dto\Api\BookingRequestData;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Requests\Booking\CreateBookingRequestRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Business;
use App\Models\Service;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\IdempotencyService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BookingService $bookings,
        private readonly AvailabilityService $availability,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function availableDates(Request $request, Business $business): JsonResponse
    {
        $serviceId = $request->input('service_id');
        $data = $request->validate([
            'service_id' => ['required', 'ulid'],
            'service_price_id' => ['sometimes', 'ulid', Rule::exists('service_prices', 'id')
                ->where('service_id', is_string($serviceId) ? $serviceId : null)->where('is_current', true)],
            'people_count' => ['nullable', 'integer', 'min:1', 'max:20'],
        ], [
            'service_id.required' => 'Please choose a service to see available booking dates.',
            'service_id.ulid' => 'The selected service is invalid. Please choose another service.',
            'service_price_id.ulid' => 'The selected service option is invalid. Please choose another duration.',
            'service_price_id.exists' => 'The selected service option is no longer available. Please choose another duration.',
            'people_count.integer' => 'Please enter a whole number of people.',
            'people_count.min' => 'At least one person is required for a booking.',
            'people_count.max' => 'You can book for up to 20 people per request.',
        ]);
        $service = $business->services()->find($data['service_id']);
        if ($service === null) {
            throw ApiException::notFound('The selected service is not available at this business. Please choose another service.');
        }
        $availability = $this->availability->availableDatesFor($business, $service, (int) ($data['people_count'] ?? 1), $data['service_price_id'] ?? null);

        return $this->success([
            'business_id' => $business->getKey(),
            'timezone' => $business->timezone,
            'dates' => $availability['dates'],
            'availability' => $availability['availability'],
        ], $availability['message']);
    }

    /**
     * GET /businesses/{business}/services/{service}/availability
     */
    public function availability(Request $request, Business $business, Service $service): JsonResponse
    {
        abort_unless($service->business_id === $business->getKey(), 404);
        abort_unless($business->is_online && $business->status === 'active', 404);
        $data = $request->validate([
            'business_id' => ['sometimes', 'ulid', 'in:'.$business->getKey()],
            'service_price_id' => ['sometimes', 'ulid', Rule::exists('service_prices', 'id')
                ->where('service_id', $service->getKey())->where('is_current', true)],
            'date' => ['required', 'date', 'after_or_equal:'.now($business->timezone)->toDateString()],
            'timezone' => ['nullable', 'timezone:all'],
            'days' => ['nullable', 'integer', 'between:1,14'],
            'people_count' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $people = (int) ($data['people_count'] ?? 1);
        $days = (int) ($data['days'] ?? 1);
        $timezone = $data['timezone'] ?? $business->timezone;

        $dates = collect(range(0, $days - 1))
            ->map(fn (int $i) => Carbon::parse($data['date'], $timezone)->startOfDay()->addDays($i));

        $businessDays = [];
        $availability = $dates->map(function (Carbon $date) use ($service, $business, $people, $data, $timezone, &$businessDays): array {
            $localDate = $date->copy()->setTimezone($business->timezone)->startOfDay();
            $lastDate = $date->copy()->endOfDay()->setTimezone($business->timezone)->startOfDay();
            $slots = collect();
            $isClosed = true;

            while ($localDate->lte($lastDate)) {
                $key = $localDate->toDateString();
                $businessDays[$key] ??= [
                    'slots' => $this->availability->slotsFor($service, $business, $localDate, $people, $data['service_price_id'] ?? null),
                    'is_closed' => $this->availability->isClosureDate($business, $localDate),
                ];
                $isClosed = $isClosed && $businessDays[$key]['is_closed'];
                foreach ($businessDays[$key]['slots'] as $slot) {
                    $start = $localDate->copy()->setTimeFromTimeString($slot['starts_at'])->setTimezone($timezone);
                    $end = $localDate->copy()->setTimeFromTimeString($slot['ends_at'])->setTimezone($timezone);
                    if ($start->toDateString() === $date->toDateString()) {
                        $slots->push(['starts_at' => $start->format('H:i'), 'ends_at' => $end->format('H:i')]);
                    }
                }
                $localDate->addDay();
            }

            return ['date' => $date->toDateString(), 'is_closed' => $slots->isEmpty() && $isClosed, 'slots' => $slots->sortBy('starts_at')->values()];
        });

        $price = $service->prices()
            ->where('is_current', true)
            ->when(isset($data['service_price_id']), fn (Builder $query) => $query->whereKey($data['service_price_id']))
            ->firstOrFail();
        $service->setAttribute('price', $price->price_minor);
        $service->setAttribute('currency', $price->currency);
        $service->load('currentPrices');

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
                    (new BookingRequestData($bookingRequest->load(['service', 'servicePrice', 'business', 'booking'])))->toArray(),
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
        if ($bookingRequest->user_id !== $request->user()->getKey()) {
            throw ApiException::forbidden('This booking request belongs to another customer.');
        }

        $bookingRequest->load(['service', 'servicePrice', 'business', 'booking']);

        return $this->success((new BookingRequestData($bookingRequest))->toArray(), 'Booking request status loaded.');
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
            ->with(['items', 'business.locations', 'payments'])
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

        $booking->load(['items', 'business.locations', 'payments', 'review', 'assignedStaff', 'assignedStaffMembers']);

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

        return $this->resource(BookingResource::make($updated->load(['items', 'business.locations'])), 'Booking cancelled.');
    }
}
