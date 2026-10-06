<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'about' => $this->about,
            'hero_image_url' => $this->hero_image_path ? url('storage/'.$this->hero_image_path) : null,
            'status' => $this->status,
            'is_verified' => (bool) $this->is_verified,
            'is_insured' => (bool) $this->is_insured,
            'is_online' => (bool) $this->is_online,
            'rating_average' => (float) $this->rating_average,
            'rating_count' => (int) $this->rating_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'branches' => BusinessLocationResource::collection($this->whenLoaded('locations')),
            'primary_branch' => new BusinessLocationResource($this->whenLoaded('locations', fn () => $this->locations->first())),
            'services' => ServiceResource::collection($this->whenLoaded('services')),
            'offers' => OfferResource::collection($this->whenLoaded('offers')),
            'reviews' => ReviewResource::collection($this->whenLoaded('reviews')),
        ];
    }
}
