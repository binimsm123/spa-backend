<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BookingStaffService
{
    public function __construct(
        private readonly StaffAvailabilityService $staffing,
        private readonly AvailabilityService $availability,
    ) {}

    /** @return array{required_staff_count: int, suggested_staff: array<int, array{id: string, display_name: ?string, job_title: ?string}>} */
    public function suggestions(Booking $booking): array
    {
        $booking->loadMissing(['items', 'business']);
        $count = $this->staffing->requiredStaffForBooking($booking);
        [$start, $end] = $this->interval($booking);
        $staff = collect();
        $service = $this->service($booking);
        if ($service !== null && $this->availability->isOpenDuring($booking->business, $start, $end)) {
            $schedule = $this->staffing->scheduleFor($booking->business, $start, exceptBookingId: $booking->id);
            $staff = $this->staffing->suggestionsFor($service, $schedule, $start, $end, $count);
        }

        return [
            'required_staff_count' => $count,
            'suggested_staff' => $staff->map(fn ($membership): array => [
                'id' => $membership->user_id, 'display_name' => $membership->user->display_name, 'job_title' => $membership->user->job_title,
            ])->all(),
        ];
    }

    /** @param array<int, string> $staffIds */
    public function assign(Booking $booking, array $staffIds): Booking
    {
        return DB::transaction(function () use ($booking, $staffIds): Booking {
            $business = Business::query()->whereKey($booking->business_id)->lockForUpdate()->firstOrFail();
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->with('items')->firstOrFail();
            if (! in_array($booking->status->value, ['awaiting_payment', 'confirmed', 'in_progress'], true)) {
                throw ApiException::conflict(ErrorCode::BookingStatusTransitionInvalid, 'Staff can only be assigned to active bookings.');
            }
            $count = $this->staffing->requiredStaffForBooking($booking);
            if (count($staffIds) !== $count || count(array_unique($staffIds)) !== $count) {
                throw ApiException::validation('Assign exactly '.$count.' staff members to this booking.', ['staff_user_ids' => ['This booking requires '.$count.' distinct staff members.']]);
            }
            [$start, $end] = $this->interval($booking);
            $service = $this->service($booking);
            if ($service === null || ! $this->availability->isOpenDuring($business, $start, $end)) {
                throw ApiException::conflict(ErrorCode::BookingSlotConflict, 'The booking is outside the current business opening hours.');
            }
            $schedule = $this->staffing->scheduleFor($business, $start, true, $booking->id);
            $staff = $this->staffing->suggestionsFor($service, $schedule, $start, $end, $count, $staffIds);
            if ($staff->count() !== $count) {
                throw ApiException::conflict(ErrorCode::BookingSlotConflict, 'The selected staff must have the required expertise and be available for the entire booking.');
            }

            $booking->assignedStaffMembers()->sync($staffIds);
            $booking->forceFill(['assigned_staff_user_id' => $staffIds[0]])->save();

            return $booking->load(['assignedStaff', 'assignedStaffMembers', 'items']);
        }, 3);
    }

    private function service(Booking $booking): ?Service
    {
        $serviceId = $booking->items->first()?->service_id;

        return $serviceId === null ? null : Service::query()->where('business_id', $booking->business_id)->find($serviceId);
    }

    /** @return array{Carbon, Carbon} */
    private function interval(Booking $booking): array
    {
        return [
            Carbon::parse($booking->getRawOriginal('starts_at'), $booking->timezone),
            Carbon::parse($booking->getRawOriginal('ends_at'), $booking->timezone),
        ];
    }
}
