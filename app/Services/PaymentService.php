<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tip;
use App\Models\User;
use App\Services\Payments\PaymentGatewayFactory;
use App\Support\ApiException;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly NotificationService $notifications,
        private readonly PaymentGatewayFactory $gateways,
        private readonly OfferService $offers,
    ) {}

    /**
     * Create a payment attempt and return it with a checkout URL.
     */
    public function initiate(User $user, Booking $booking, int $amountMinor, PaymentGateway $gateway, PaymentPurpose $purpose): Payment
    {
        return DB::transaction(function () use ($user, $booking, $amountMinor, $gateway, $purpose): Payment {
            $booking = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($booking->user_id !== $user->getKey()) {
                throw ApiException::forbidden('This booking belongs to another customer.');
            }

            if ($purpose === PaymentPurpose::Booking
                && ($booking->status !== BookingStatus::AwaitingPayment || $booking->starts_at->isPast())) {
                throw ApiException::conflict(ErrorCode::BookingStatusTransitionInvalid, 'This booking is no longer available for payment.');
            }

            $driver = $this->gateways->make($gateway);

            $payment = Payment::query()->create([
                'booking_id' => $booking->getKey(),
                'user_id' => $user->getKey(),
                'gateway' => $gateway->value,
                'purpose' => $purpose->value,
                'amount_minor' => $amountMinor,
                'currency' => $booking->currency,
                'status' => PaymentStatus::Pending->value,
                'gateway_reference' => (string) Str::ulid(),
            ]);

            $payload = $driver->initiate($payment);
            $payment->forceFill([
                'checkout_url' => $gateway === PaymentGateway::Khalti ? $payload['payment_url'] : config('services.esewa.checkout_url'),
                'gateway_payload' => $payload,
            ])->save();

            return $payment->refresh();
        });
    }

    /**
     * Post-service tip checkout (completed bookings only).
     */
    public function tipCheckout(User $user, Booking $booking, int $amountMinor, PaymentGateway $gateway): Tip
    {
        return DB::transaction(function () use ($user, $booking, $amountMinor, $gateway): Tip {
            if (! $booking->isCompleted()) {
                throw ApiException::conflict(ErrorCode::BookingNotCompleted, 'Tips are only available for completed appointments.');
            }

            if ($amountMinor <= 0) {
                throw ApiException::validation('Tip amount must be positive.', [
                    'amount_minor' => ['Tip amount must be a positive integer amount.'],
                ]);
            }

            $payment = $this->initiate($user, $booking, $amountMinor, $gateway, PaymentPurpose::Tip);

            /** @var Tip $tip */
            $tip = Tip::query()->create([
                'booking_id' => $booking->getKey(),
                'user_id' => $user->getKey(),
                'payment_id' => $payment->getKey(),
                'amount_minor' => $amountMinor,
                'currency' => $booking->currency,
                'status' => 'pending',
            ]);

            return $tip;
        });
    }

    /** @param array<string, mixed> $verification */
    public function complete(Payment $payment, array $verification): Payment
    {
        return DB::transaction(function () use ($payment, $verification): Payment {
            $booking = Booking::query()->whereKey($payment->booking_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($payment->status !== PaymentStatus::Pending) {
                return $payment;
            }

            $payment->forceFill([
                'status' => PaymentStatus::Succeeded,
                'gateway_payload' => array_merge($payment->gateway_payload ?? [], ['verification' => $verification]),
                'paid_at' => now(),
            ])->save();

            if ($payment->purpose === PaymentPurpose::Booking && $payment->amount_minor === $booking->total_minor) {
                if ($booking->status === BookingStatus::AwaitingPayment) {
                    $this->offers->confirmReservation($booking);
                }
                $this->bookings->markPaid($booking, actor: null);
            } elseif ($payment->purpose === PaymentPurpose::Tip) {
                $payment->tip?->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
                $this->notifications->notify(
                    $booking->user,
                    NotificationType::Payment,
                    'Tip received',
                    'Thank you for the tip!',
                    ['booking_id' => $booking->getKey()],
                );
            }

            return $payment->refresh();
        });
    }

    public function fail(Payment $payment): void
    {
        Payment::query()->whereKey($payment->getKey())
            ->where('status', PaymentStatus::Pending->value)
            ->update(['status' => PaymentStatus::Failed->value]);
    }

    /**
     * Mark a payment refunded (admin workflow).
     */
    public function refund(Payment $payment, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason): Payment {
            $booking = Booking::query()->whereKey($payment->booking_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($payment->status !== PaymentStatus::Succeeded) {
                throw ApiException::conflict(ErrorCode::PaymentFailed, 'Only successful payments can be refunded.');
            }

            $payment->forceFill(['status' => PaymentStatus::Refunded->value])->save();

            if ($payment->purpose === PaymentPurpose::Booking) {
                $booking->forceFill(['status' => BookingStatus::Refunded->value])->save();

                // Release any applied offer redemption and refund its points.
                $redemption = $booking->offerRedemption ?? $booking->offerReservation;
                if ($redemption !== null) {
                    $this->offers->release($redemption);
                }
            }

            AuditLogger::record(
                auth()->user(),
                'payment.refund',
                'payment',
                $payment->getKey(),
                ['status' => 'succeeded'],
                ['status' => 'refunded'],
                $reason,
            );

            return $payment->refresh();
        });
    }
}
