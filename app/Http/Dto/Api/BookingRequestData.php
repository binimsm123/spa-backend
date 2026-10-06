<?php

namespace App\Http\Dto\Api;

use App\Models\BookingRequest;

final readonly class BookingRequestData
{
    public function __construct(private BookingRequest $request) {}

    public function toArray(): array
    {
        $service = $this->request->service;
        $times = $this->request->relationLoaded('times') ? $this->request->times : collect();

        return [
            'booking_request_id' => $this->request->getKey(),
            'status' => $this->request->status->value,
            'provider_id' => $this->request->business_id,
            'service_id' => $this->request->service_id,
            'requested_date' => $this->request->requested_date?->toDateString(),
            'timezone' => $this->request->timezone,
            'available_times' => $times->map(fn ($time) => $time->starts_at?->format('H:i'))->values()->all(),
            'expires_in' => $this->request->expires_at ? max(0, now()->diffInSeconds($this->request->expires_at, false)) : null,
        ];
    }
}
