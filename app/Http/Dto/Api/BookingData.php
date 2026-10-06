<?php

namespace App\Http\Dto\Api;

use App\Models\Booking;

final readonly class BookingData
{
    public function __construct(private Booking $booking) {}

    public function toArray(): array
    {
        $item = $this->booking->items->first();

        return [
            'booking_id' => $this->booking->getKey(),
            'status' => $this->booking->status->value,
            'provider_id' => $this->booking->business_id,
            'service_id' => $item?->service_id,
            'appointment_date' => $this->booking->appointment_date?->toDateString(),
            'appointment_time' => $this->booking->starts_at?->format('H:i'),
            'timezone' => $this->booking->timezone,
            'subtotal' => self::major($this->booking->subtotal_minor),
            'tax' => self::major($this->booking->tax_minor),
            'tip' => self::major($this->booking->tip_minor),
            'total' => self::major($this->booking->total_minor),
            'currency' => $this->booking->currency,
        ];
    }

    private static function major(?int $minor): int|float
    {
        $value = ((int) $minor) / 100;
        return fmod($value, 1.0) === 0.0 ? (int) $value : $value;
    }
}
