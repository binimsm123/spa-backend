<?php

namespace App\Http\Dto\Api;

use App\Models\User;

final readonly class LocationsData
{
    public function __construct(private User $user, private mixed $locations, private ?string $region = null) {}

    public function toArray(): array
    {
        return [
            'selected_location_id' => $this->user->selected_location_id,
            'region' => $this->region,
            'locations' => collect($this->locations)->map(fn ($location) => [
                'id' => $location->id,
                'name' => $location->name,
                'city' => $location->city,
                'available' => (bool) $location->is_serviceable,
            ])->values()->all(),
        ];
    }
}
