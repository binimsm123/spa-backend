<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffHourResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'weekday' => $this->weekday,
            'opens_at' => $this->opens_at?->format('H:i'),
            'closes_at' => $this->closes_at?->format('H:i'),
            'is_closed' => $this->is_closed,
        ];
    }
}
