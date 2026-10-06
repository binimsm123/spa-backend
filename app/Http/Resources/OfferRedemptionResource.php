<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferRedemptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'offer' => new OfferResource($this->whenLoaded('offer')),
            'offer_id' => $this->offer_id,
            'discount_minor' => (int) $this->discount_minor,
            'points_used' => (int) $this->points_used,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'redeemed_at' => $this->redeemed_at?->toIso8601String(),
        ];
    }
}
