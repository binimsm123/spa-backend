<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessClosure;
use App\Models\BusinessHour;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AvailabilityService
{
    /**
     * Next five open dates in the business timezone; offline status excludes today only.
     *
     * @return Collection<int, string>
     */
    public function availableDatesFor(Business $business): Collection
    {
        if ($business->status !== 'active') {
            return collect();
        }

        $openWeekdays = $business->hours()
            ->where('is_closed', false)
            ->whereColumn('opens_at', '<', 'closes_at')
            ->pluck('weekday');

        if ($openWeekdays->isEmpty()) {
            return collect();
        }

        $candidateDate = now($business->timezone)->startOfDay();
        if (! $business->is_online) {
            $candidateDate->addDay();
        }

        $closures = $business->closures()->where('ends_on', '>=', $candidateDate->toDateString())->get();
        $availableDates = collect();

        while ($availableDates->count() < 5) {
            $dateString = $candidateDate->toDateString();
            $closure = $closures->first(fn (BusinessClosure $closure): bool => $closure->starts_on->toDateString() <= $dateString
                && $closure->ends_on->toDateString() >= $dateString);

            if ($closure !== null) {
                $candidateDate = Carbon::parse($closure->ends_on->toDateString(), $business->timezone)->addDay();

                continue;
            }

            if ($openWeekdays->contains($candidateDate->dayOfWeek)) {
                $availableDates->push($dateString);
            }

            $candidateDate->addDay();
        }

        return $availableDates;
    }

    /**
     * Available start times for a service at a business on a given date.
     *
     * @return Collection<int, array{starts_at: string, ends_at: string}>
     */
    public function slotsFor(Service $service, Business $business, Carbon $date, int $peopleCount = 1, ?string $servicePriceId = null): Collection
    {
        $this->assertBookable($service, $peopleCount);

        $weekday = (int) $date->format('w'); // 0 (Sun) .. 6 (Sat)

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

        $opensAt = $date->copy()->setTimeFromTimeString($hours->getRawOriginal('opens_at'));
        $closesAt = $date->copy()->setTimeFromTimeString($hours->getRawOriginal('closes_at'));

        $price = ServicePrice::query()
            ->where('service_id', $service->getKey())
            ->where('is_current', true)
            ->when($servicePriceId !== null, fn (Builder $query) => $query->whereKey($servicePriceId))
            ->first();

        if ($price === null) {
            throw ApiException::conflict(ErrorCode::ServiceUnavailable, 'This service has no price at the selected business.');
        }

        $duration = $price->durationMinutes();
        $slotMinutes = $this->slotGranularity($duration);
        $now = now($business->timezone);

        // Existing bookings that block the business for the whole day (excluding cancelled/expired).
        $taken = $this->bookedIntervals($business, $date);

        $slots = [];
        $cursor = $opensAt->copy();

        while ($cursor->copy()->addMinutes($duration)->lte($closesAt)) {
            $endsAt = $cursor->copy()->addMinutes($duration);

            $overlaps = $taken->contains(fn (array $interval) => $cursor->lt($interval['ends_at']) && $endsAt->gt($interval['starts_at']));

            if (! $overlaps && $cursor->gt($now)) {
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
    public function assertIntervalAvailable(Service $service, Business $business, Carbon $startsAt, Carbon $endsAt, int $peopleCount): void
    {
        $this->assertBookable($service, $peopleCount);

        if ($startsAt->lte(now($business->timezone))) {
            throw ApiException::conflict(ErrorCode::BookingSlotConflict, 'The selected time has already started. Pick another time.');
        }

        if ($endsAt->lte($startsAt) || ! $this->isOpenDuring($business, $startsAt, $endsAt)) {
            throw ApiException::conflict(ErrorCode::BookingSlotConflict, 'The selected time is outside the business opening hours. Pick another time.');
        }

        $conflict = Booking::query()
            ->where('business_id', $business->getKey())
            ->whereIn('status', ['awaiting_payment', 'confirmed', 'in_progress', 'completed'])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->lockForUpdate()
            ->first(['id']);

        if ($conflict !== null) {
            throw ApiException::conflict(ErrorCode::BookingSlotConflict, 'The selected time overlaps another booking. Pick another time.');
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
     * Blocking intervals from existing bookings for a business on a date.
     *
     * @return Collection<int, array{starts_at: Carbon, ends_at: Carbon}>
     */
    private function bookedIntervals(Business $business, Carbon $date): Collection
    {
        return Booking::query()
            ->where('business_id', $business->getKey())
            ->whereDate('appointment_date', $date->toDateString())
            ->whereIn('status', ['awaiting_payment', 'confirmed', 'in_progress', 'completed'])
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Booking $booking) => [
                'starts_at' => Carbon::parse($booking->starts_at),
                'ends_at' => Carbon::parse($booking->ends_at),
            ]);
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
