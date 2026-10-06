<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tip;
use App\Models\User;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Create a payment attempt and return it with a checkout URL.
     */
    public function initiate(User $user, Booking $booking, int $amountMinor, PaymentGateway $gateway, PaymentPurpose $purpose): Payment
    {
        return DB::transaction(function () use ($user, $booking, $amountMinor, $gateway, $purpose): Payment {
            $payment = Payment::query()->create([
                'booking_id' => $booking->getKey(),
                'user_id' => $user->getKey(),
                'gateway' => $gateway->value,
                'purpose' => $purpose->value,
                'amount_minor' => $amountMinor,
                'currency' => $booking->currency,
                'status' => PaymentStatus::Pending->value,
                'gateway_reference' => 'ref_'.Str::ulid(),
            ]);

            $payment->forceFill([
                'checkout_url' => $this->buildCheckoutUrl($payment),
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

    /**
     * Idempotent webhook handling with gateway signature verification.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(PaymentGateway $gateway, string $signature, array $payload): Payment
    {
        // Signature verification: production gateways sign callbacks; reject mismatches.
        $expected = hash_hmac('sha256', json_encode($payload) ?: '', (string) config('spa.payments.webhook_secret'));

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new ApiException(ErrorCode::PaymentGatewayError, 'Invalid webhook signature.');
        }

        $reference = (string) ($payload['gateway_reference'] ?? '');
        $state = (string) ($payload['status'] ?? '');

        /** @var Payment|null $payment */
        $payment = Payment::query()->where('gateway_reference', $reference)->first();

        if ($payment === null) {
            throw new ApiException(ErrorCode::NotFound, 'Unknown payment reference.');
        }

        return DB::transaction(function () use ($payment, $state): Payment {
            // Lock the payment row so concurrent webhook deliveries are idempotent.
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            if ($payment->status === PaymentStatus::Succeeded) {
                return $payment; // already processed
            }

            if ($state === 'succeeded') {
                $payment->forceFill([
                    'status' => PaymentStatus::Succeeded->value,
                    'paid_at' => now(),
                ])->save();

                if ($payment->purpose === PaymentPurpose::Booking) {
                    $this->bookings->markPaid($payment->booking, actor: null);
                } elseif ($payment->purpose === PaymentPurpose::Tip) {
                    $payment->tip?->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

                    $this->notifications->notify(
                        $payment->booking->user,
                        NotificationType::Payment,
                        'Tip received',
                        'Thank you for the tip!',
                        ['booking_id' => $payment->booking_id],
                    );
                }
            } elseif (in_array($state, ['failed', 'cancelled'], true)) {
                $payment->forceFill(['status' => $state === 'failed' ? PaymentStatus::Failed->value : PaymentStatus::Cancelled->value])->save();
            }

            return $payment->refresh();
        });
    }

    /**
     * Mark a payment refunded (admin workflow).
     */
    public function refund(Payment $payment, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason): Payment {
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            if ($payment->status !== PaymentStatus::Succeeded) {
                throw ApiException::conflict(ErrorCode::PaymentFailed, 'Only successful payments can be refunded.');
            }

            $payment->forceFill(['status' => PaymentStatus::Refunded->value])->save();

            if ($payment->purpose === PaymentPurpose::Booking) {
                $booking = $payment->booking;
                $booking->forceFill(['status' => \App\Enums\BookingStatus::Refunded->value])->save();

                // Release any applied offer redemption and refund its points.
                $redemption = $booking->offerRedemption;
                if ($redemption !== null) {
                    app(OfferService::class)->release($redemption);
                }
            }

            \App\Support\AuditLogger::record(
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

    private function buildCheckoutUrl(Payment $payment): string
    {
        // Placeholder redirect URL; real gateways return their own hosted checkout.
        return rtrim((string) config('app.url'), '/').'/payments/'.$payment->gateway_reference.'/redirect';
    }
}
