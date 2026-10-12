<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $location = $this->locations->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'about' => $this->about,
            'hero_image_url' => $this->hero_image_path ? url('storage/'.$this->hero_image_path) : null,
            'status' => $this->status,
            'is_verified' => (bool) $this->is_verified,
            'is_online' => (bool) $this->is_online,
            'home_service' => (bool) $this->home_service,
            'location_service' => (bool) $this->location_service,
            'staff_number' => (int) $this->staff_number,
            'rating_average' => (float) $this->rating_average,
            'rating_count' => (int) $this->rating_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'locations' => $this->locations->map(fn ($location) => $location->only(['id', 'business_id', 'address', 'city', 'latitude', 'longitude', 'service_availability'])),
            'address' => $location?->address,
            'city' => $location?->city,
            'latitude' => $location?->latitude,
            'longitude' => $location?->longitude,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'timezone' => $this->timezone,
            'hours' => $this->whenLoaded('hours', fn () => $this->hours->map(fn ($hour) => [
                'weekday' => (int) $hour->weekday,
                'opens_at' => $hour->opens_at?->format('H:i'),
                'closes_at' => $hour->closes_at?->format('H:i'),
                'is_closed' => (bool) $hour->is_closed,
            ])),
            'closures' => $this->whenLoaded('closures', fn () => $this->closures->map(fn ($closure) => [
                'id' => $closure->id,
                'starts_on' => $closure->starts_on?->toDateString(),
                'ends_on' => $closure->ends_on?->toDateString(),
                'reason' => $closure->reason,
            ])),
            'services' => ServiceResource::collection($this->whenLoaded('services')),
            'offers' => OfferResource::collection($this->whenLoaded('offers')),
            'reviews' => ReviewResource::collection($this->whenLoaded('reviews')),
        ];
    }
}
