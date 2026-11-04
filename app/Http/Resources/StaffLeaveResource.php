<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffLeaveResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'starts_at' => $this->starts_at?->format('H:i'),
            'ends_at' => $this->ends_at?->format('H:i'),
            'is_full_day' => $this->starts_at === null,
            'reason' => $this->reason,
        ];
    }
}
