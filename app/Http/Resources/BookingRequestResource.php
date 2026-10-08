<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business' => new BusinessResource($this->whenLoaded('business')),
            'business_id' => $this->business_id,
            'service' => new ServiceResource($this->whenLoaded('service')),
            'people_count' => (int) $this->people_count,
            'requested_date' => $this->requested_date?->toDateString(),
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'times' => $this->whenLoaded('times', fn () => $this->times->map(fn ($t) => [
                'id' => $t->id,
                'starts_at' => $t->starts_at->toIso8601String(),
                'ends_at' => $t->ends_at->toIso8601String(),
                'is_available' => (bool) $t->is_available,
            ])),
            'booking_id' => $this->whenLoaded('booking', fn () => $this->booking?->getKey()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
