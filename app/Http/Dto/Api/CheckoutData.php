<?php

namespace App\Http\Dto\Api;

use App\Enums\PaymentGateway;
use App\Models\Booking;
use App\Models\CheckoutQuote;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayFactory;

final readonly class CheckoutData
{
    public function __construct(private Booking $booking, private PaymentGatewayFactory $gateways) {}

    public function summary(): array
    {
        $major = static fn ($value) => (int) round(((int) $value) / 100);

        return [
            'booking' => self::bookingDetails($this->booking),
            'pricing' => ['subtotal' => $major($this->booking->subtotal_minor), 'tax_label' => 'Tax (2%)', 'tax_rate' => 0.02, 'tax' => $major($this->booking->tax_minor), 'tip' => $major($this->booking->tip_minor), 'discount' => $major($this->booking->discount_minor), 'total' => $major($this->booking->total_minor), 'currency' => $this->booking->currency],
            'tip_options' => [50, 100, 200], 'promo' => null,
            'payment_providers' => collect(['esewa', 'khalti', 'mypay'])->map(fn ($id) => ['id' => $id, 'display_name' => ucfirst($id), 'logo_url' => null, 'available' => $this->gateways->available(PaymentGateway::from($id))])->all(),
        ];
    }

    public static function quote(CheckoutQuote $quote, Booking $booking): array
    {
        $major = static fn ($value) => (int) round(((int) $value) / 100);

        return ['quote_id' => $quote->id, 'booking_id' => $quote->booking_id, 'booking' => self::bookingDetails($booking), 'pricing' => ['subtotal' => $major($quote->subtotal_minor), 'tax_label' => 'Tax (2%)', 'tax_rate' => 0.02, 'tax' => $major($quote->tax_minor), 'tip' => $major($quote->tip_minor), 'discount' => $major($quote->discount_minor), 'total' => $major($quote->total_minor), 'currency' => $quote->currency], 'promo' => null, 'expires_in' => $quote->expires_at ? max(0, now()->diffInSeconds($quote->expires_at, false)) : null];
    }

    private static function bookingDetails(Booking $booking): array
    {
        $item = $booking->items->first();

        return [
            'id' => $booking->id,
            'status' => $booking->status->value,
            'appointment_date' => $booking->appointment_date?->toDateString(),
            'appointment_time' => $booking->starts_at?->format('H:i'),
            'timezone' => $booking->timezone,
            'location' => $booking->business?->locations->first()?->address,
            'provider' => ['id' => $booking->business_id, 'name' => $booking->business?->name],
            'service' => [
                'id' => $item?->service_id,
                'name' => $item?->service_name_snapshot,
                'description' => null,
                'duration_minutes' => $item?->duration_minutes_snapshot,
            ],
        ];
    }

    public static function payment(Payment $payment): array
    {
        return ['payment_id' => $payment->id, 'booking_id' => $payment->booking_id, 'provider' => $payment->gateway?->value, 'amount' => (int) round($payment->amount_minor / 100), 'currency' => $payment->currency, 'checkout_url' => $payment->checkout_url, 'gateway_data' => array_diff_key($payment->gateway_payload ?? [], ['verification' => true]), 'expires_in' => $payment->checkoutExpiresIn(), 'status' => $payment->status?->value];
    }
}
