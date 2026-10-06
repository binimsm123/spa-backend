<?php

namespace App\Services;

use App\Enums\BookingRequestStatus;
use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BookingRequest;
use App\Models\BookingRequestTime;
use App\Models\BusinessLocation;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly NotificationService $notifications,
        private readonly RewardService $rewards,
    ) {}

    // -------------------------------------------------------------------------
    // Customer flow: request -> times proposed -> confirmed -> booking
    // -------------------------------------------------------------------------

    /**
     * Step 1: customer requests a service date. Business supplies times later.
     */
    public function createRequest(User $user, array $data): BookingRequest
    {
        return DB::transaction(function () use ($user, $data): BookingRequest {
            $service = Service::query()->with('business')->findOrFail($data['service_id']);
            $business = $service->business;

            if (! $business->is_online || $business->status !== 'active') {
                throw ApiException::conflict(ErrorCode::BusinessOffline, 'This business is not accepting online bookings.');
            }

            if (! $service->is_bookable || $service->status !== 'active') {
                throw ApiException::conflict(ErrorCode::ServiceNotBookable, 'This service cannot be booked online.');
            }

            /** @var BusinessLocation $branch */
            $branch = BusinessLocation::query()
                ->where('business_id', $business->getKey())
                ->whereKey($data['business_location_id'])
                ->where('is_active', true)
                ->firstOrFail();

            $requestedDate = Carbon::parse($data['requested_date'])->startOfDay();

            if ($requestedDate->isPast()) {
                throw ApiException::validation('The requested date cannot be in the past.', [
                    'requested_date' => ['The requested date cannot be in the past.'],
                ]);
            }

            $peopleCount = (int) ($data['people_count'] ?? 1);
            if ($peopleCount > (int) $service->max_people) {
                throw ApiException::conflict(ErrorCode::CapacityExceeded, 'The requested party size exceeds service capacity.');
            }

            /** @var BookingRequest $request */
            $request = $user->bookingRequests()->create([
                'business_id' => $business->getKey(),
                'business_location_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'people_count' => $peopleCount,
                'requested_date' => $requestedDate,
                'timezone' => $data['timezone'] ?? $branch->timezone,
                'status' => BookingRequestStatus::Pending->value,
                'idempotency_key' => (string) Str::ulid(),
                'expires_at' => now()->addHours((int) config('spa.booking.request_ttl_hours', 24)),
            ]);

            $this->notifications->notify(
                $user,
                NotificationType::Booking,
                'Booking request sent',
                'We sent your request to '.$business->name.'. We will notify you when times are proposed.',
                ['booking_request_id' => $request->getKey()],
            );

            return $request->refresh();
        });
    }

    /**
     * Step 2: business proposes available times for the request.
     *
     * @param  array<int, string>  $startsAtList
     */
    public function proposeTimes(BookingRequest $request, array $startsAtList, User $actor): BookingRequest
    {
        return DB::transaction(function () use ($request, $startsAtList, $actor): BookingRequest {
            if ($request->status !== BookingRequestStatus::Pending) {
                throw ApiException::conflict(ErrorCode::BookingStatusTransitionInvalid, 'Times can only be proposed for pending requests.');
            }

            if ($request->expires_at->isPast()) {
                throw ApiException::conflict(ErrorCode::BookingRequestExpired, 'This booking request has expired.');
            }

            $service = $request->service;
            /** @var BusinessLocation $branch */
            $branch = $request->businessLocation;

            $rows = [];
            foreach ($startsAtList as $startsAt) {
                $start = Carbon::parse($startsAt, $branch->timezone);
                $end = $start->copy()->addMinutes((int) $service->duration_minutes);

                if (! $this->availability->isOpenAt($branch, $start)) {
                    throw ApiException::validation('A proposed time is outside opening hours.', [
                        'starts_at' => ['Proposed time '.$start->format('H:i').' is outside opening hours.'],
                    ]);
                }

                $rows[] = [
                    'booking_request_id' => $request->getKey(),
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'is_available' => true,
                ];
            }

            $request->times()->createMany($rows);

            $request->forceFill(['status' => BookingRequestStatus::TimesProposed->value])->save();

            $this->notifications->notify(
                $request->user,
                NotificationType::Booking,
                'Times available',
                $request->business->name.' proposed times for your request.',
                ['booking_request_id' => $request->getKey()],
            );

            return $request->refresh();
        });
    }

    /**
     * Step 3: customer confirms one proposed time -> creates awaiting-payment booking.
     */
    public function confirmRequest(User $user, BookingRequest $request, string $timeId): Booking
    {
        return DB::transaction(function () use ($user, $request, $timeId): Booking {
            if ($request->user_id !== $user->getKey()) {
                throw ApiException::forbidden('This booking request belongs to another customer.');
            }

            if ($request->status !== BookingRequestStatus::TimesProposed) {
                throw ApiException::conflict(ErrorCode::BookingRequestNotAccepted, 'Confirm the request only after times are proposed and available.');
            }

            if ($request->expires_at->isPast()) {
                throw ApiException::conflict(ErrorCode::BookingRequestExpired, 'This booking request has expired.');
            }

            /** @var BookingRequestTime|null $time */
            $time = $request->times()
                ->whereKey($timeId)
                ->where('is_available', true)
                ->lockForUpdate()
                ->first();

            if ($time === null) {
                throw ApiException::conflict(ErrorCode::BookingRequestNotAccepted, 'The selected time is no longer available.');
            }

            /** @var BusinessLocation $branch */
            $branch = $request->businessLocation;
            $service = $request->service;

            // Re-check slot availability inside the transaction (race safety).
            $slots = $this->availability->slotsFor(
                $service,
                $branch,
                Carbon::parse($request->requested_date),
                (int) $request->people_count,
            );

            $stillFree = $slots->first(fn (array $slot) => $slot['starts_at'] === $time->starts_at->format('H:i'));
            if ($stillFree === null) {
                throw ApiException::conflict(ErrorCode::BookingSlotConflict, 'That time was just booked by someone else. Pick another slot.');
            }

            $price = $this->resolvePrice($service, $branch);
            $people = (int) $request->people_count;

            /** @var Booking $booking */
            $booking = Booking::query()->create([
                'booking_request_id' => $request->getKey(),
                'user_id' => $user->getKey(),
                'business_id' => $request->business_id,
                'business_location_id' => $branch->getKey(),
                'appointment_date' => $time->starts_at->toDateString(),
                'starts_at' => $time->starts_at,
                'ends_at' => $time->ends_at,
                'timezone' => $branch->timezone,
                'status' => BookingStatus::AwaitingPayment->value,
                'people_count' => $people,
                'subtotal_minor' => $price->price_minor * $people,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'tip_minor' => 0,
                'total_minor' => $price->price_minor * $people,
                'reward_points_earned' => 0,
                'currency' => $price->currency,
            ]);

            BookingItem::query()->create([
                'booking_id' => $booking->getKey(),
                'service_id' => $service->getKey(),
                'service_name_snapshot' => $service->name,
                'duration_minutes_snapshot' => (int) $service->duration_minutes,
                'max_people_snapshot' => (int) $service->max_people,
                'unit_price_minor' => $price->price_minor,
                'currency' => $price->currency,
                'quantity' => $people,
            ]);

            $booking->statusHistory()->create([
                'changed_by_user_id' => $user->getKey(),
                'old_status' => null,
                'new_status' => BookingStatus::AwaitingPayment->value,
                'note' => 'Booking created from a confirmed request time.',
                'created_at' => now(),
            ]);

            $request->forceFill(['status' => BookingRequestStatus::Confirmed->value, 'confirmed_at' => now()])->save();

            $this->notifications->notify(
                $user,
                NotificationType::Booking,
                'Booking awaiting payment',
                'Complete payment to confirm your appointment at '.$request->business->name.'.',
                ['booking_id' => $booking->getKey()],
            );

            return $booking->refresh();
        });
    }

    // -------------------------------------------------------------------------
    // Business-side request handling
    // -------------------------------------------------------------------------

    public function declineRequest(BookingRequest $request, ?string $reason = null): BookingRequest
    {
        return DB::transaction(function () use ($request, $reason): BookingRequest {
            if (! in_array($request->status, [BookingRequestStatus::Pending, BookingRequestStatus::TimesProposed], true)) {
                throw ApiException::conflict(ErrorCode::BookingStatusTransitionInvalid, 'Only pending or proposed requests can be declined.');
            }

            $request->forceFill(['status' => BookingRequestStatus::Declined->value, 'declined_at' => now()])->save();

            $this->notifications->notify(
                $request->user,
                NotificationType::Booking,
                'Booking request declined',
                $request->business->name.' cannot serve your request.'.($reason !== null ? ' Reason: '.$reason : ''),
                ['booking_request_id' => $request->getKey()],
            );

            return $request->refresh();
        });
    }

    // -------------------------------------------------------------------------
    // Booking status workflow
    // -------------------------------------------------------------------------

    public function transitionStatus(Booking $booking, BookingStatus $target, ?User $actor = null, ?string $note = null): Booking
    {
        return DB::transaction(function () use ($booking, $target, $actor, $note): Booking {
            $current = $booking->status;

            if (! $current->canTransitionTo($target)) {
                throw ApiException::conflict(
                    ErrorCode::BookingStatusTransitionInvalid,
                    "Cannot move a {$current->value} booking to {$target->value}.",
                );
            }

            $booking->forceFill([
                'status' => $target->value,
                'confirmed_at' => $target === BookingStatus::Confirmed ? now() : $booking->confirmed_at,
                'completed_at' => $target === BookingStatus::Completed ? now() : $booking->completed_at,
                'cancelled_at' => $target === BookingStatus::Cancelled ? now() : $booking->cancelled_at,
                'cancelled_by_user_id' => $target === BookingStatus::Cancelled ? $actor?->getKey() : $booking->cancelled_by_user_id,
                'cancel_reason' => $target === BookingStatus::Cancelled ? $note : $booking->cancel_reason,
            ])->save();

            $booking->statusHistory()->create([
                'changed_by_user_id' => $actor?->getKey(),
                'old_status' => $current->value,
                'new_status' => $target->value,
                'note' => $note,
                'created_at' => now(),
            ]);

            $this->notifyStatusChange($booking, $target);

            return $booking->refresh();
        });
    }

    /**
     * Mark a booking paid and award reward points once.
     */
    public function markPaid(Booking $booking, ?User $actor = null): void
    {
        if ($booking->status !== BookingStatus::AwaitingPayment) {
            return;
        }

        $booking->forceFill(['status' => BookingStatus::Confirmed->value, 'confirmed_at' => now()])->save();

        $booking->statusHistory()->create([
            'changed_by_user_id' => $actor?->getKey(),
            'old_status' => BookingStatus::AwaitingPayment->value,
            'new_status' => BookingStatus::Confirmed->value,
            'note' => 'Payment succeeded.',
            'created_at' => now(),
        ]);

        $this->rewards->awardForBooking($booking);

        $this->notifications->notify(
            $booking->user,
            NotificationType::Payment,
            'Payment confirmed',
            'Your appointment at '.$booking->business->name.' is confirmed.',
            ['booking_id' => $booking->getKey()],
        );
    }

    public function cancel(Booking $booking, ?User $actor = null, ?string $reason = null): Booking
    {
        return $this->transitionStatus($booking, BookingStatus::Cancelled, $actor, $reason);
    }

    // -------------------------------------------------------------------------
    // Pricing helper
    // -------------------------------------------------------------------------

    public function resolvePrice(Service $service, BusinessLocation $branch): ServicePrice
    {
        /** @var ServicePrice|null $price */
        $price = ServicePrice::query()
            ->where('service_id', $service->getKey())
            ->where('business_location_id', $branch->getKey())
            ->where('is_current', true)
            ->orderByDesc('starts_at')
            ->first();

        if ($price === null) {
            throw ApiException::conflict(ErrorCode::ServiceUnavailable, 'This service has no price at the selected branch.');
        }

        return $price;
    }

    // -------------------------------------------------------------------------
    // Expiry jobs (called from the scheduler)
    // -------------------------------------------------------------------------

    public function expireStaleRequests(): int
    {
        return BookingRequest::query()
            ->whereIn('status', [BookingRequestStatus::Pending->value, BookingRequestStatus::TimesProposed->value])
            ->where('expires_at', '<', now())
            ->update(['status' => BookingRequestStatus::Expired->value]);
    }

    public function expireStaleBookings(): int
    {
        return Booking::query()
            ->where('status', BookingStatus::AwaitingPayment->value)
            ->where('starts_at', '<', now())
            ->update(['status' => BookingStatus::Expired->value]);
    }

    // -------------------------------------------------------------------------
    // Notification helper
    // -------------------------------------------------------------------------

    private function notifyStatusChange(Booking $booking, BookingStatus $target): void
    {
        [$title, $body] = match ($target) {
            BookingStatus::Confirmed => ['Booking confirmed', 'Your appointment is confirmed.'],
            BookingStatus::InProgress => ['Appointment started', 'Your appointment has started.'],
            BookingStatus::Completed => ['Appointment completed', 'Thanks for visiting. You can review and tip now.'],
            BookingStatus::Cancelled => ['Booking cancelled', 'Your booking was cancelled.'],
            BookingStatus::Refunded => ['Booking refunded', 'Your payment was refunded.'],
            default => [null, null],
        };

        if ($title === null) {
            return;
        }

        $this->notifications->notify(
            $booking->user,
            NotificationType::Booking,
            $title,
            $body,
            ['booking_id' => $booking->getKey()],
        );
    }
}
