<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_url' => $this->image_path ? url('storage/'.$this->image_path) : null,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'service_prices' => ServicePriceResource::collection($this->whenLoaded('currentPrices')),
            'max_people' => (int) $this->max_people,
            'is_bookable' => (bool) $this->is_bookable,
            'status' => $this->status,
            'price_minor' => $this->when(isset($this->price), fn () => (int) $this->price),
            'currency' => $this->when(isset($this->currency), fn () => $this->currency),
        ];
    }
}
