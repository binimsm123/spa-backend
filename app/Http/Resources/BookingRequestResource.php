<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Collection;
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
            'service_price_id' => $this->service_price_id,
            'service' => $this->whenLoaded('service', function (): ServiceResource {
                $service = clone $this->service;
                $service->setRelation('currentPrices', new Collection($this->servicePrice ? [$this->servicePrice] : []));

                return new ServiceResource($service);
            }),
            'people_count' => (int) $this->people_count,
            'requested_date' => $this->requested_date?->toDateString(),
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'decline_reason' => $this->decline_reason,
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
