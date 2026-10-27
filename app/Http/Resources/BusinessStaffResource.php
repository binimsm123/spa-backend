<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessStaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'job_title' => $this->job_title,
            'mobile_number' => $this->mobile_number,
            'avatar_url' => $this->avatar_path ? route('business.staff-roster.avatar', ['business' => $this->business_id, 'staff' => $this->id]) : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
