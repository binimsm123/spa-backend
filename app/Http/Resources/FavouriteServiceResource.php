<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FavouriteServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'service_id' => $this->id,
            'business_id' => $this->business_id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->imageUrl(),
        ];
    }
}
