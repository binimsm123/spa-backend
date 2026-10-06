<?php

namespace App\Http\Dto\Api;

use App\Models\Location;
use App\Models\User;

final readonly class LocationUpdateData
{
    public function __construct(private User $user, private Location $location) {}

    public function toArray(): array
    {
        return [
            'user_id' => $this->user->getKey(),
            'location' => [
                'id' => $this->location->getKey(),
                'name' => $this->location->name,
                'city' => $this->location->city,
                'available' => (bool) $this->location->is_serviceable,
            ],
            'updated_at' => $this->user->updated_at?->toIso8601String(),
        ];
    }
}
