<?php

namespace App\Http\Dto\Api;

use App\Models\BookingRequest;

final readonly class BookingRequestData
{
    public function __construct(private BookingRequest $request) {}

    public function toArray(): array
    {
        return [
            'booking_request_id' => $this->request->getKey(),
            'status' => $this->request->status->value,
            'rejection_reason' => $this->request->decline_reason,
            'provider_id' => $this->request->business_id,
            'service_id' => $this->request->service_id,
            'service_price_id' => $this->request->service_price_id,
            'requested_date' => $this->request->requested_date?->toDateString(),
            'requested_time' => $this->request->requested_time?->format('H:i'),
            'people_count' => $this->request->people_count,
            'timezone' => $this->request->timezone,
            'booking_id' => $this->request->booking?->getKey(),
            'expires_in' => $this->request->expires_at ? max(0, now()->diffInSeconds($this->request->expires_at, false)) : null,
        ];
    }
}
