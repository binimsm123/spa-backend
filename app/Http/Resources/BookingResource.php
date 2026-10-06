<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'user_id' => $this->user_id,
            'business_id' => $this->business_id,
            'business_location_id' => $this->business_location_id,
            'customer' => new UserResource($this->whenLoaded('user')),
            'business' => new BusinessResource($this->whenLoaded('business')),
            'business_location' => new BusinessLocationResource($this->whenLoaded('businessLocation')),
            'service_name' => $this->whenLoaded('items', fn () => $this->items->first()?->service_name_snapshot),
            'duration_minutes' => $this->whenLoaded('items', fn () => $this->items->first()?->duration_minutes_snapshot),
            'people_count' => (int) $this->people_count,
            'appointment_date' => $this->appointment_date?->toDateString(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'timezone' => $this->timezone,
            'assigned_staff' => new UserResource($this->whenLoaded('assignedStaff')),
            'pricing' => $this->whenLoaded('items', fn () => [
                'subtotal_minor' => (int) $this->subtotal_minor,
                'tax_minor' => (int) $this->tax_minor,
                'discount_minor' => (int) $this->discount_minor,
                'tip_minor' => (int) $this->tip_minor,
                'total_minor' => (int) $this->total_minor,
                'currency' => $this->currency,
            ]),
            'reward_points_earned' => (int) $this->reward_points_earned,
            'payment_status' => $this->when(
                $this->relationLoaded('payments'),
                fn () => $this->payments->firstWhere('purpose', 'booking')?->status?->value,
            ),
            'has_review' => $this->whenLoaded('review', fn () => $this->review !== null),
            'created_at' => $this->created_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
