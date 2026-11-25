<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminOfferResource extends OfferResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'usage_count' => (int) $this->usage_count,
            'total_usage_limit' => $this->total_usage_limit,
            'per_user_limit' => (int) $this->per_user_limit,
        ];
    }
}
