<?php

namespace App\Http\Dto\Api;

use App\Models\Booking;
use App\Models\CheckoutQuote;
use App\Models\Payment;

final readonly class CheckoutData
{
    public function __construct(private Booking $booking) {}

    public function summary(): array
    {
        $item = $this->booking->items->first();
        $major = static fn ($value) => (int) round(((int) $value) / 100);

        return [
            'booking' => ['id' => $this->booking->id, 'status' => $this->booking->status->value, 'appointment_date' => $this->booking->appointment_date?->toDateString(), 'appointment_time' => $this->booking->starts_at?->format('H:i'), 'timezone' => $this->booking->timezone, 'location' => $this->booking->business?->address, 'provider' => ['id' => $this->booking->business_id, 'name' => $this->booking->business?->name], 'service' => ['id' => $item?->service_id, 'name' => $item?->service_name_snapshot, 'description' => null, 'duration_minutes' => $item?->duration_minutes_snapshot]],
            'pricing' => ['subtotal' => $major($this->booking->subtotal_minor), 'tax_label' => 'Tax (2%)', 'tax_rate' => 0.02, 'tax' => $major($this->booking->tax_minor), 'tip' => $major($this->booking->tip_minor), 'discount' => $major($this->booking->discount_minor), 'total' => $major($this->booking->total_minor), 'currency' => $this->booking->currency],
            'tip_options' => [50, 100, 200], 'promo' => null,
            'payment_providers' => collect(['esewa', 'khalti', 'mypay'])->map(fn ($id) => ['id' => $id, 'display_name' => ucfirst($id), 'logo_url' => null, 'available' => true])->all(),
        ];
    }

    public static function quote(CheckoutQuote $quote): array
    {
        $major = static fn ($value) => (int) round(((int) $value) / 100);

        return ['quote_id' => $quote->id, 'booking_id' => $quote->booking_id, 'pricing' => ['subtotal' => $major($quote->subtotal_minor), 'tax_label' => 'Tax (2%)', 'tax_rate' => 0.02, 'tax' => $major($quote->tax_minor), 'tip' => $major($quote->tip_minor), 'discount' => $major($quote->discount_minor), 'total' => $major($quote->total_minor), 'currency' => $quote->currency], 'promo' => null, 'expires_in' => $quote->expires_at ? max(0, now()->diffInSeconds($quote->expires_at, false)) : null];
    }

    public static function payment(Payment $payment): array
    {
        return ['payment_id' => $payment->id, 'booking_id' => $payment->booking_id, 'provider' => $payment->gateway?->value, 'amount' => (int) round($payment->amount_minor / 100), 'currency' => $payment->currency, 'checkout_url' => $payment->checkout_url, 'expires_in' => 900, 'status' => $payment->status?->value];
    }
}
