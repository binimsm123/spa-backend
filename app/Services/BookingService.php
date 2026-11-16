<?php

namespace App\Services;

use App\Enums\BookingRequestStatus;
use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BookingRequest;
use App\Models\Business;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
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
    // Customer selects a slot; the business approves or rejects the request.
    // -------------------------------------------------------------------------

    /**
     * Create a pending business approval request for a selected service interval.
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

            abort_unless($business->getKey() === $data['business_id'], 404);

            $price = $this->resolvePrice($service, $data['service_price_id'] ?? null);

            $requestedDate = Carbon::parse($data['requested_date'], $business->timezone)->startOfDay();

            if ($requestedDate->lt(now($business->timezone)->startOfDay())) {
                throw ApiException::validation('The requested date cannot be in the past.', [
                    'requested_date' => ['The requested date cannot be in the past.'],
                ]);
            }

            $peopleCount = (int) ($data['people_count'] ?? 1);
            if ($peopleCount > (int) $service->max_people) {
                throw ApiException::conflict(ErrorCode::CapacityExceeded, 'The requested party size exceeds service capacity.');
            }

            $requestedTime = $data['requested_time'];
            if (! $this->availability->slotsFor($service, $business, $requestedDate, $peopleCount, $price->id)->contains('starts_at', $requestedTime)) {
                throw ApiException::conflict(ErrorCode::BookingSlotConflict, 'The selected time is no longer available. Please choose another time.');
            }

            /** @var BookingRequest $request */
            $request = $user->bookingRequests()->create([
                'business_id' => $business->getKey(),
                'service_id' => $service->getKey(),
                'service_price_id' => $price->getKey(),
                'people_count' => $peopleCount,
                'requested_date' => $requestedDate,
                'requested_time' => $requestedTime,
                'timezone' => $business->timezone,
                'status' => BookingRequestStatus::Pending->value,
                'idempotency_key' => (string) Str::ulid(),
                'expires_at' => now()->addHours((int) config('spa.booking.request_ttl_hours', 24)),
            ]);

            $this->notifications->notify(
                $user,
                NotificationType::Booking,
                'Booking request sent',
                'Your appointment request is awaiting approval from '.$business->name.'.',
                ['booking_request_id' => $request->getKey()],
            );

            foreach ($business->members()->wherePivot('is_active', true)->wherePivotIn('role', ['owner', 'manager'])->where('users.is_active', true)->get() as $member) {
                $this->notifications->notify($member, NotificationType::Booking, 'New booking request',
                    $service->name.' requested for '.$requestedDate->toDateString().' at '.$requestedTime.'.',
                    ['booking_request_id' => $request->id]);
            }

            return $request;
        });
    }

    /**
     * Approve the requested interval and reserve staffing capacity without assigning staff.
     */
    public function approveRequest(User $actor, BookingRequest $request): Booking
    {
        return DB::transaction(function () use ($actor, $request): Booking {
            $request = BookingRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $actor->belongsToBusiness($request->business)) {
                throw ApiException::forbidden('You do not manage this business.');
            }

            if ($request->booking()->exists()) {
                throw ApiException::conflict(ErrorCode::BookingRequestAlreadyBooked, 'This request already has a booking. Please view your existing booking.');
            }

            if ($request->status !== BookingRequestStatus::Pending) {
                throw ApiException::conflict(ErrorCode::BookingRequestNotAccepted, 'Only pending booking requests can be approved.');
            }

            if ($request->expires_at->isPast()) {
                throw ApiException::conflict(ErrorCode::BookingRequestExpired, 'This booking request has expired.');
            }

            $business = Business::query()->whereKey($request->business_id)->lockForUpdate()->firstOrFail();
            $service = Service::query()->whereKey($request->service_id)->lockForUpdate()->firstOrFail();
            if ($service->business_id !== $business->id) {
                throw ApiException::notFound('The selected service is not available at this business.');
            }
            if ($request->requested_time === null) {
                throw ApiException::conflict(ErrorCode::BookingRequestNotAccepted, 'This request has no selected time. Please ask the customer to submit a new request.');
            }
            $price = $this->resolvePrice($service, $request->service_price_id);
            $startsAt = Carbon::parse($request->requested_date->toDateString().' '.$request->requested_time->format('H:i'), $request->timezone);
            $endsAt = $startsAt->copy()->addMinutes($price->durationMinutes());

            $this->availability->assertIntervalAvailable(
                $service,
                $business,
                $startsAt,
                $endsAt,
                (int) $request->people_count,
            );

            $people = (int) $request->people_count;

            /** @var Booking $booking */
            $booking = Booking::query()->create([
                'booking_request_id' => $request->getKey(),
                'user_id' => $request->user_id,
                'business_id' => $request->business_id,
                'appointment_date' => $startsAt->toDateString(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => $business->timezone,
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
                'duration_minutes_snapshot' => $price->durationMinutes(),
                'max_people_snapshot' => (int) $service->max_people,
                'required_staff_count_snapshot' => $service->required_staff_count,
                'unit_price_minor' => $price->price_minor,
                'currency' => $price->currency,
                'quantity' => $people,
            ]);

            $booking->statusHistory()->create([
                'changed_by_user_id' => $actor->getKey(),
                'old_status' => null,
                'new_status' => BookingStatus::AwaitingPayment->value,
                'note' => 'Booking created after business approval of the requested time.',
                'created_at' => now(),
            ]);

            $request->forceFill(['status' => BookingRequestStatus::Approved->value, 'accepted_at' => now()])->save();

            $this->notifications->notify(
                $request->user,
                NotificationType::Booking,
                'Booking awaiting payment',
                'Complete payment to confirm your appointment at '.$request->business->name.'.',
                ['booking_id' => $booking->getKey()],
            );

            return $booking->refresh();
        }, 3);
    }

    // -------------------------------------------------------------------------
    // Business-side request handling
    // -------------------------------------------------------------------------

    public function rejectRequest(User $actor, BookingRequest $request, ?string $reason = null): BookingRequest
    {
        return DB::transaction(function () use ($actor, $request, $reason): BookingRequest {
            $request = BookingRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $actor->belongsToBusiness($request->business)) {
                throw ApiException::forbidden('You do not manage this business.');
            }
            if ($request->status !== BookingRequestStatus::Pending) {
                throw ApiException::conflict(ErrorCode::BookingStatusTransitionInvalid, 'Only pending booking requests can be rejected.');
            }
            if ($request->expires_at->isPast()) {
                throw ApiException::conflict(ErrorCode::BookingRequestExpired, 'This booking request has expired.');
            }

            $request->forceFill([
                'status' => BookingRequestStatus::Rejected->value,
                'declined_at' => now(),
                'decline_reason' => $reason,
            ])->save();

            $this->notifications->notify(
                $request->user,
                NotificationType::Booking,
                'Booking request rejected',
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

    public function resolvePrice(Service $service, ?string $servicePriceId = null): ServicePrice
    {
        /** @var ServicePrice|null $price */
        $price = ServicePrice::query()
            ->where('service_id', $service->getKey())
            ->where('is_current', true)
            ->when($servicePriceId !== null, fn (Builder $query) => $query->whereKey($servicePriceId))
            ->first();

        if ($price === null) {
            throw ApiException::conflict(ErrorCode::ServiceUnavailable, 'This service has no price at the business.');
        }

        return $price;
    }

    // -------------------------------------------------------------------------
    // Expiry jobs (called from the scheduler)
    // -------------------------------------------------------------------------

    public function expireStaleRequests(): int
    {
        return BookingRequest::query()
            ->where('status', BookingRequestStatus::Pending->value)
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
