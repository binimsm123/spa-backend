<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'subtotal_minor' => (int) $this->subtotal_minor,
            'tax_minor' => (int) $this->tax_minor,
            'tip_minor' => (int) $this->tip_minor,
            'discount_minor' => (int) $this->discount_minor,
            'total_minor' => (int) $this->total_minor,
            'currency' => $this->currency,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
