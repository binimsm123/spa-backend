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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AvailabilityService
{
    /**
     * Available start times for a service at a business on a given date.
     *
     * @return Collection<int, array{starts_at: string, ends_at: string}>
     */
    public function slotsFor(Service $service, Business $business, Carbon $date, int $peopleCount = 1): Collection
    {
        if (! $service->is_bookable || $service->status !== 'active') {
            throw ApiException::conflict(ErrorCode::ServiceNotBookable, 'This service cannot be booked online.');
        }

        if ($peopleCount > $service->max_people) {
            throw ApiException::conflict(ErrorCode::CapacityExceeded, 'The requested party size exceeds service capacity.');
        }

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
        if ($this->isClosureDate($business, $at)) {
            return false;
        }

        $weekday = (int) $at->format('w');

        /** @var BusinessHour|null $hours */
        $hours = BusinessHour::query()
            ->where('business_id', $business->getKey())
            ->where('weekday', $weekday)
            ->first();

        if ($hours === null || $hours->is_closed || $hours->opens_at === null || $hours->closes_at === null) {
            return false;
        }

        $opensAt = $at->copy()->setTimeFromTimeString($hours->getRawOriginal('opens_at'));
        $closesAt = $at->copy()->setTimeFromTimeString($hours->getRawOriginal('closes_at'));

        return $at->betweenIncluded($opensAt, $closesAt);
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
