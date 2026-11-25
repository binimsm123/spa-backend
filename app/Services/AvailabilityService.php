<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessClosure;
use App\Models\BusinessHour;
use App\Models\BusinessUser;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AvailabilityService
{
    private const SEARCH_WINDOW_DAYS = 30;

    public function __construct(
        private readonly StaffAvailabilityService $staffing,
        private readonly AvailabilityCache $cache,
    ) {}

    /**
     * Up to five dates with service slots in the next 30 days, in the business timezone.
     * Offline status excludes today only.
     *
     * @return array{
     *     dates: Collection<int, string>,
     *     availability: Collection<int, array{date: string, slots: array<int, array{starts_at: string, ends_at: string}>}>,
     *     message: string
     * }
     */
    public function availableDatesFor(Business $business, Service $service, int $peopleCount = 1, ?string $servicePriceId = null): array
    {
        $this->assertBookable($service, $peopleCount);

        if ($service->business_id !== $business->getKey()) {
            throw ApiException::notFound('The selected service is not available at this business. Please choose another service.');
        }

        if ($business->status !== 'active') {
            return ['dates' => collect(), 'availability' => collect(), 'message' => 'This business is not accepting bookings at the moment. Please check again later.'];
        }

        $key = 'availability:v1:'.hash('sha256', json_encode([
            $business->getKey(), $business->timezone, $business->is_online, $business->getRawOriginal('updated_at'),
            $service->getKey(), $service->required_staff_count, $service->getRawOriginal('updated_at'),
            $servicePriceId === null ? null : strtolower($servicePriceId), $peopleCount,
            now($business->timezone)->toDateString(), self::SEARCH_WINDOW_DAYS,
        ], JSON_THROW_ON_ERROR));
        $result = $this->cache->remember($key, fn (): array => $this->calculateAvailability($business, $service, $servicePriceId));

        if (! $result['has_opening_hours']) {
            return ['dates' => collect(), 'availability' => collect(), 'message' => 'This business has no available opening hours. Please contact the business for availability.'];
        }

        $now = now($business->timezone);
        $availability = collect($result['availability'])->map(function (array $day) use ($business, $now): array {
            $day['slots'] = array_values(array_filter($day['slots'], fn (array $slot): bool => Carbon::parse($day['date'].' '.$slot['starts_at'], $business->timezone)->gt($now)));

            return $day;
        })->filter(fn (array $day): bool => $day['slots'] !== [])->take(5)->values();

        $dateCount = $availability->count();
        $searchWindowDays = self::SEARCH_WINDOW_DAYS;
        $message = match ($dateCount) {
            0 => "No booking dates are available for this service in the next {$searchWindowDays} days. Please try another service or check again later.",
            1 => "Only 1 booking date is available for this service in the next {$searchWindowDays} days.",
            2, 3, 4 => "Only {$dateCount} booking dates are available for this service in the next {$searchWindowDays} days.",
            default => 'Available booking dates loaded.',
        };

        if (! $business->is_online) {
            $message = 'This business is offline today. '.$message;
        }

        return ['dates' => $availability->pluck('date'), 'availability' => $availability, 'message' => $message];
    }

    /** @return array{has_opening_hours: bool, availability: array<int, array{date: string, slots: array<int, array{starts_at: string, ends_at: string}>}>} */
    private function calculateAvailability(Business $business, Service $service, ?string $servicePriceId): array
    {
        $hours = $business->hours()->where('is_closed', false)->whereColumn('opens_at', '<', 'closes_at')->get()->keyBy('weekday');
        if ($hours->isEmpty()) {
            return ['has_opening_hours' => false, 'availability' => []];
        }

        $candidateDate = now($business->timezone)->startOfDay();
        $today = $candidateDate->toDateString();
        $searchEndsAt = $candidateDate->copy()->addDays(self::SEARCH_WINDOW_DAYS);
        if (! $business->is_online) {
            $candidateDate->addDay();
        }
        $closures = $business->closures()->where('ends_on', '>=', $candidateDate->toDateString())
            ->where('starts_on', '<', $searchEndsAt->toDateString())->get();
        $availability = [];
        $dateLimit = 5;
        $price = null;
        $schedule = null;
        $scheduleEndsAt = null;

        while (count($availability) < $dateLimit && $candidateDate->lt($searchEndsAt)) {
            $dateString = $candidateDate->toDateString();
            $closure = $closures->first(fn (BusinessClosure $closure): bool => $closure->starts_on->toDateString() <= $dateString
                && $closure->ends_on->toDateString() >= $dateString);
            if ($closure !== null) {
                $candidateDate = Carbon::parse($closure->ends_on->toDateString(), $business->timezone)->addDay();

                continue;
            }

            if ($hours->has($candidateDate->dayOfWeek)) {
                $price ??= $this->priceFor($service, $servicePriceId);
                if ($schedule === null || $candidateDate->gt($scheduleEndsAt)) {
                    $scheduleEndsAt = $candidateDate->copy()->addDays(13)->min($searchEndsAt->copy()->subDay());
                    $schedule = $this->staffing->scheduleForRange($business, $candidateDate, $scheduleEndsAt);
                    if ($schedule['staff']->filter(fn (BusinessUser $member): bool => $member->services->contains('id', $service->id))->count() < $service->required_staff_count) {
                        break;
                    }
                }
                $bookingsFrom = $candidateDate->copy()->subDays(2)->toDateString();
                $bookingsThrough = $candidateDate->copy()->addDays(2)->toDateString();
                $daySchedule = [
                    'staff' => $schedule['staff'],
                    'bookings' => $schedule['bookings']->filter(fn (Booking $booking): bool => $booking->getRawOriginal('appointment_date') >= $bookingsFrom
                        && $booking->getRawOriginal('appointment_date') <= $bookingsThrough),
                ];
                $slots = $this->calculateSlots($service, $business, $candidateDate, $hours->get($candidateDate->dayOfWeek), $price, $daySchedule);
                if ($slots->isNotEmpty()) {
                    $availability[] = ['date' => $dateString, 'slots' => $slots->all()];
                    // Keep a backup when today's final slot could expire during the cache lifetime.
                    if ($dateString === $today) {
                        $dateLimit = 6;
                    }
                }
            }
            $candidateDate->addDay();
        }

        return ['has_opening_hours' => true, 'availability' => $availability];
    }

    /**
     * Available start times for a service at a business on a given date.
     *
     * @return Collection<int, array{starts_at: string, ends_at: string}>
     */
    public function slotsFor(Service $service, Business $business, Carbon $date, int $peopleCount = 1, ?string $servicePriceId = null): Collection
    {
        $this->assertBookable($service, $peopleCount);

        if ($service->business_id !== $business->getKey() || $business->status !== 'active' || ! $business->is_online) {
            return collect();
        }

        return $this->slotsForDate($service, $business, $date, $servicePriceId);
    }

    /** @return Collection<int, array{starts_at: string, ends_at: string}> */
    private function slotsForDate(Service $service, Business $business, Carbon $date, ?string $servicePriceId): Collection
    {
        $date = Carbon::parse($date->toDateString(), $business->timezone);
        $weekday = $date->dayOfWeek;

        /** @var BusinessHour|null $hours */
        $hours = BusinessHour::query()
            ->where('business_id', $business->getKey())
            ->where('weekday', $weekday)
            ->first();

        if ($hours === null || $hours->is_closed || $hours->opens_at === null || $hours->closes_at === null) {
            return collect();
        }

        if ($this->isClosureDate($business, $date)) {
            return collect();
        }

        return $this->calculateSlots($service, $business, $date, $hours, $this->priceFor($service, $servicePriceId), $this->staffing->scheduleFor($business, $date));
    }

    private function priceFor(Service $service, ?string $servicePriceId): ServicePrice
    {
        $price = ServicePrice::query()
            ->where('service_id', $service->getKey())
            ->where('is_current', true)
            ->when($servicePriceId !== null, fn (Builder $query) => $query->whereKey($servicePriceId))
            ->first();

        if ($price === null) {
            throw ApiException::conflict(ErrorCode::ServiceUnavailable, 'This service is currently unavailable for booking. Please choose another service or check again later.');
        }

        return $price;
    }

    /**
     * @param  array{staff: Collection<int, BusinessUser>, bookings: Collection<int, Booking>}  $schedule
     * @return Collection<int, array{starts_at: string, ends_at: string}>
     */
    private function calculateSlots(Service $service, Business $business, Carbon $date, BusinessHour $hours, ServicePrice $price, array $schedule): Collection
    {
        $opensAt = $date->copy()->setTimeFromTimeString($hours->getRawOriginal('opens_at'));
        $closesAt = $date->copy()->setTimeFromTimeString($hours->getRawOriginal('closes_at'));
        $duration = $price->durationMinutes();
        $slotMinutes = $this->slotGranularity($duration);
        $now = now($business->timezone);

        $slots = [];
        $cursor = $opensAt->copy();

        while ($cursor->copy()->addMinutes($duration)->lte($closesAt)) {
            $endsAt = $cursor->copy()->addMinutes($duration);

            if ($cursor->gt($now) && $this->staffing->suggestionsFor($service, $schedule, $cursor, $endsAt, $service->required_staff_count)->isNotEmpty()) {
                $slots[] = [
                    'starts_at' => $cursor->format('H:i'),
                    'ends_at' => $endsAt->format('H:i'),
                ];
            }

            $cursor->addMinutes($slotMinutes);
        }

        return collect($slots);
    }

    /**
     * Whether a business is open at the given datetime (hours + closures).
     */
    public function isOpenAt(Business $business, Carbon $at): bool
    {
        return $this->isOpenDuring($business, $at, $at);
    }

    public function isOpenDuring(Business $business, Carbon $startsAt, Carbon $endsAt): bool
    {
        $startsAt = $startsAt->copy()->setTimezone($business->timezone);
        $endsAt = $endsAt->copy()->setTimezone($business->timezone);

        if ($endsAt->lt($startsAt)
            || $startsAt->toDateString() !== $endsAt->toDateString()
            || $this->isClosureDate($business, $startsAt)) {
            return false;
        }

        $weekday = (int) $startsAt->format('w');

        /** @var BusinessHour|null $hours */
        $hours = BusinessHour::query()
            ->where('business_id', $business->getKey())
            ->where('weekday', $weekday)
            ->first();

        if ($hours === null || $hours->is_closed || $hours->opens_at === null || $hours->closes_at === null) {
            return false;
        }

        $opensAt = $startsAt->copy()->setTimeFromTimeString($hours->getRawOriginal('opens_at'));
        $closesAt = $startsAt->copy()->setTimeFromTimeString($hours->getRawOriginal('closes_at'));

        return $startsAt->gte($opensAt) && $endsAt->lte($closesAt);
    }

    /**
     * Validate a proposed interval inside the caller's booking transaction.
     */
    public function assertIntervalAvailable(Service $service, Business $business, Carbon $startsAt, Carbon $endsAt, int $peopleCount, bool $lock = true): void
    {
        $this->assertBookable($service, $peopleCount);

        if ($startsAt->lte(now($business->timezone))) {
            throw ApiException::conflict(ErrorCode::BookingSlotConflict, 'The selected time has already started. Pick another time.');
        }

        if ($endsAt->lte($startsAt) || ! $this->isOpenDuring($business, $startsAt, $endsAt)) {
            throw ApiException::conflict(ErrorCode::BookingSlotConflict, 'The selected time is outside the business opening hours. Pick another time.');
        }

        if ($business->status !== 'active' || ! $business->is_online) {
            throw ApiException::conflict(ErrorCode::BusinessOffline, 'This business is not accepting online bookings.');
        }

        $startsAt = $startsAt->copy()->setTimezone($business->timezone);
        $endsAt = $endsAt->copy()->setTimezone($business->timezone);
        $schedule = $this->staffing->scheduleFor($business, $startsAt, $lock);
        if ($this->staffing->suggestionsFor($service, $schedule, $startsAt, $endsAt, $service->required_staff_count)->isEmpty()) {
            throw ApiException::conflict(ErrorCode::BookingSlotConflict, 'There are not enough available staff for the selected time. Pick another time.');
        }
    }

    private function assertBookable(Service $service, int $peopleCount): void
    {
        if (! $service->is_bookable || $service->status !== 'active') {
            throw ApiException::conflict(ErrorCode::ServiceNotBookable, 'This service cannot be booked online.');
        }

        if ($peopleCount > $service->max_people) {
            throw ApiException::conflict(ErrorCode::CapacityExceeded, 'The requested party size exceeds service capacity.');
        }
    }

    /**
     * Is the business closed (holiday/maintenance) on the given date?
     */
    public function isClosureDate(Business $business, Carbon $date): bool
    {
        return BusinessClosure::query()
            ->where('business_id', $business->getKey())
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->exists();
    }

    /**
     * Slot granularity: 15/30/60 minute steps depending on duration.
     */
    private function slotGranularity(int $durationMinutes): int
    {
        return match (true) {
            $durationMinutes <= 30 => 15,
            $durationMinutes <= 60 => 30,
            default => 60,
        };
    }
}
