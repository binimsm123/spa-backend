<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'service_id' => $this->service_id,
            'business' => $this->whenLoaded('business', fn () => $this->business->only(['id', 'name'])),
            'service' => $this->whenLoaded('service', fn () => [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'service_prices' => $this->service->currentPrices->map(fn ($price) => [
                    'price_minor' => $price->price_minor,
                    'currency' => $price->currency,
                    'duration_minutes' => $price->durationMinutes(),
                ]),
            ]),
            'is_platform_sponsored' => (bool) $this->is_platform_sponsored,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'image_urls' => $this->image_urls ?? [],
            'discount_type' => $this->discount_type?->value,
            'discount_value' => (int) $this->discount_value,
            'minimum_booking_amount_minor' => (int) $this->minimum_booking_amount_minor,
            'max_discount_minor' => $this->max_discount_minor !== null ? (int) $this->max_discount_minor : null,
            'currency' => $this->currency,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'status' => $this->status?->value,
            'requires_reward_points' => (bool) $this->requires_reward_points,
            'reward_points_cost' => (int) $this->reward_points_cost,
        ];
    }
}
