<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'mobile_number' => $this->mobile,
            'email' => $this->email,
            'avatar_url' => $this->image ? url('storage/'.$this->image) : null,
            'timezone' => $this->timezone,
            'selected_location_id' => $this->selected_location_id,
            'reward_points_balance' => (int) $this->reward_points,
            'mobile_verified' => $this->mobile_verified_at !== null,
            'email_verified' => $this->email_verified_at !== null,
            'roles' => $this->getRoleNames(),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
