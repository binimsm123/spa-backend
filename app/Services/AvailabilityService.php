<?php

namespace App\Services;

use App\Models\BusinessClosure;
use App\Models\BusinessHour;
use App\Models\BusinessLocation;
use App\Models\Service;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AvailabilityService
{
    /**
     * Available start times for a service at a branch on a given date.
     *
     * @return Collection<int, array{starts_at: string, ends_at: string}>
     */
    public function slotsFor(Service $service, BusinessLocation $branch, Carbon $date, int $peopleCount = 1): Collection
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
            ->where('business_location_id', $branch->getKey())
            ->where('weekday', $weekday)
            ->first();

        if ($hours === null || $hours->is_closed || $hours->opens_at === null || $hours->closes_at === null) {
            return collect();
        }

        if ($this->isClosureDate($branch, $date)) {
            return collect();
        }

        $opensAt = $date->copy()->setTimeFromTimeString($hours->getRawOriginal('opens_at'));
        $closesAt = $date->copy()->setTimeFromTimeString($hours->getRawOriginal('closes_at'));

        $duration = (int) $service->duration_minutes;
        $slotMinutes = $this->slotGranularity($duration);
        $now = now($branch->timezone);

        // Existing bookings that block the branch for the whole day (excluding cancelled/expired).
        $taken = $this->bookedIntervals($branch, $date);

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
     * Whether a branch is open at the given datetime (hours + closures).
     */
    public function isOpenAt(BusinessLocation $branch, Carbon $at): bool
    {
        if ($this->isClosureDate($branch, $at)) {
            return false;
        }

        $weekday = (int) $at->format('w');

        /** @var BusinessHour|null $hours */
        $hours = BusinessHour::query()
            ->where('business_location_id', $branch->getKey())
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
     * Is the branch closed (holiday/maintenance) on the given date?
     */
    public function isClosureDate(BusinessLocation $branch, Carbon $date): bool
    {
        return BusinessClosure::query()
            ->where('business_location_id', $branch->getKey())
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->exists();
    }

    /**
     * Blocking intervals from existing bookings for a branch on a date.
     *
     * @return Collection<int, array{starts_at: Carbon, ends_at: Carbon}>
     */
    private function bookedIntervals(BusinessLocation $branch, Carbon $date): Collection
    {
        return \App\Models\Booking::query()
            ->where('business_location_id', $branch->getKey())
            ->whereDate('appointment_date', $date->toDateString())
            ->whereIn('status', ['awaiting_payment', 'confirmed', 'in_progress', 'completed'])
            ->get(['starts_at', 'ends_at'])
            ->map(fn (\App\Models\Booking $booking) => [
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
