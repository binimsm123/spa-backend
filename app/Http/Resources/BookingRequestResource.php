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
            'user_id' => $this->user_id,
            'customer' => new UserResource($this->whenLoaded('user')),
            'service_price_id' => $this->service_price_id,
            'service' => $this->whenLoaded('service', function (): ?ServiceResource {
                if ($this->service === null) {
                    return null;
                }
                $service = clone $this->service;
                $service->setRelation('currentPrices', new Collection($this->servicePrice ? [$this->servicePrice] : []));

                return new ServiceResource($service);
            }),
            'people_count' => (int) $this->people_count,
            'requested_date' => $this->requested_date?->toDateString(),
            'requested_time' => $this->requested_time?->format('H:i'),
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'rejection_reason' => $this->decline_reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'booking_id' => $this->whenLoaded('booking', fn () => $this->booking?->getKey()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
