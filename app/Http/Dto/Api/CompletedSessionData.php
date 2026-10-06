<?php

namespace App\Http\Dto\Api;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Tip;

final readonly class CompletedSessionData
{
    public function __construct(private Booking $booking, private string $receiptUrl) {}

    public function toArray(): array
    {
        $item = $this->booking->items->first();
        return [
            'booking_id' => $this->booking->id,
            'status' => $this->booking->status->value,
            'completed_at' => $this->booking->completed_at?->toIso8601String(),
            'service' => ['id' => $item?->service_id, 'name' => $item?->service_name_snapshot, 'image_url' => null, 'description' => null, 'duration_minutes' => $item?->duration_minutes_snapshot],
            'provider' => ['id' => $this->booking->business_id, 'name' => $this->booking->business?->name],
            'price' => (int) round($this->booking->subtotal_minor / 100),
            'currency' => $this->booking->currency,
            'receipt' => ['id' => $this->booking->id, 'number' => 'SPA-'.$this->booking->id, 'download_url' => $this->receiptUrl, 'expires_at' => now()->addMinutes(30)->toIso8601String()],
            'review' => $this->booking->review ? ['id' => $this->booking->review->id, 'rating' => (int) $this->booking->review->rating, 'tag' => $this->booking->review->tag, 'comments' => $this->booking->review->comments] : null,
            'tip_options' => [1000, 1500, 2000, 2500, 3000, 5000],
            'custom_tip_enabled' => true,
            'payment_providers' => ['esewa', 'khalti', 'mypay'],
        ];
    }
}
