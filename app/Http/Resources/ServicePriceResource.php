<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServicePriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'price_minor' => (int) $this->price_minor,
            'currency' => $this->currency,
            'duration' => substr((string) $this->duration, 0, 5),
            'duration_minutes' => $this->resource->durationMinutes(),
            'is_current' => (bool) $this->is_current,
        ];
    }
}
