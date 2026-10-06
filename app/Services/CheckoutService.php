<?php

namespace App\Services;

use App\Enums\PaymentGateway;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\CheckoutQuote;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\User;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Build the authoritative quote for a booking (tip + promo code).
     */
    public function quote(User $user, Booking $booking, int $tipMinor = 0, ?string $promoCode = null): CheckoutQuote
    {
        return DB::transaction(function () use ($user, $booking, $tipMinor, $promoCode): CheckoutQuote {
            $this->assertQuoteable($booking);

            $subtotal = (int) $booking->subtotal_minor;
            $tax = (int) $booking->tax_minor;
            $discount = 0;
            $offer = null;

            if ($promoCode !== null && $promoCode !== '') {
                $offer = Offer::query()->where('code', strtoupper(trim($promoCode)))->first();

                if ($offer === null) {
                    throw ApiException::conflict(ErrorCode::OfferInvalidCode, 'The promo code is not valid.');
                }

                $this->offers->assertEligible($user, $offer, $booking->items->first()->service, $subtotal);
                $discount = $this->offers->calculateDiscount($offer, $subtotal);
            }

            $total = max(0, $subtotal + $tax + $tipMinor - $discount);

            /** @var CheckoutQuote $quote */
            $quote = CheckoutQuote::query()->create([
                'booking_id' => $booking->getKey(),
                'offer_id' => $offer?->getKey(),
                'subtotal_minor' => $subtotal,
                'tax_minor' => $tax,
                'tip_minor' => $tipMinor,
                'discount_minor' => $discount,
                'total_minor' => $total,
                'currency' => $booking->currency,
                'expires_at' => now()->addMinutes((int) config('spa.checkout.quote_ttl_minutes', 15)),
            ]);

            return $quote;
        });
    }

    /**
     * Create a payment attempt from a valid, unexpired quote.
     */
    public function startCheckout(User $user, Booking $booking, CheckoutQuote $quote, string $gateway): Payment
    {
        return DB::transaction(function () use ($user, $booking, $quote, $gateway): Payment {
            $this->assertQuoteable($booking);

            if ($quote->booking_id !== $booking->getKey()) {
                throw ApiException::conflict(ErrorCode::QuoteInvalid, 'This quote does not belong to the booking.');
            }

            if ($quote->expires_at->isPast()) {
                throw ApiException::conflict(ErrorCode::QuoteExpired, 'This quote has expired. Request a new one.');
            }

            $gatewayEnum = PaymentGateway::tryFrom($gateway);

            if ($gatewayEnum === null) {
                throw ApiException::validation('Unsupported payment gateway.', [
                    'gateway' => ['Supported gateways are esewa, khalti, and mypay.'],
                ]);
            }

            return $this->payments->initiate($user, $booking, $quote->total_minor, $gatewayEnum, PaymentPurpose::Booking);
        });
    }

    private function assertQuoteable(Booking $booking): void
    {
        if ($booking->status !== \App\Enums\BookingStatus::AwaitingPayment) {
            throw ApiException::conflict(ErrorCode::BookingStatusTransitionInvalid, 'Checkout is only available for bookings awaiting payment.');
        }
    }
}
