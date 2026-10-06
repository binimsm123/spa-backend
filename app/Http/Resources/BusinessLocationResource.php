<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
    use Illuminate\Http\Resources\Json\JsonResource;

class BusinessLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_name' => $this->branch_name,
            'address' => $this->address,
            'city' => $this->city,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'timezone' => $this->timezone,
            'hours' => $this->whenLoaded('hours', fn () => $this->hours->map(fn ($h) => [
                'day_of_week' => (int) $h->weekday,
                'opens_at' => $h->opens_at,
                'closes_at' => $h->closes_at,
            ])),
            'closures' => $this->whenLoaded('closures', fn () => $this->closures->map(fn ($c) => [
                'id' => $c->id,
                'start_date' => $c->starts_on?->toDateString(),
                'end_date' => $c->ends_on?->toDateString(),
                'reason' => $c->reason,
            ])),
        ];
    }
}
