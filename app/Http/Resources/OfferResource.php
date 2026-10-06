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
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
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
