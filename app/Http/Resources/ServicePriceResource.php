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
            'duration' => $this->duration,
            'is_current' => (bool) $this->is_current,
        ];
    }
}
