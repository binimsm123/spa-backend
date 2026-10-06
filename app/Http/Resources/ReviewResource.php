<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'customer' => new UserResource($this->whenLoaded('user')),
            'service' => new ServiceResource($this->whenLoaded('service')),
            'rating' => (int) $this->rating,
            'tag' => $this->tag,
            'comments' => $this->comments,
            'is_hidden' => (bool) $this->is_hidden,
            'business' => new BusinessResource($this->whenLoaded('business')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
