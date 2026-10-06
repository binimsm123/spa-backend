<?php

namespace App\Http\Dto\Api;

use App\Models\Review;

final readonly class ReviewData
{
    public function __construct(private Review $review) {}

    public function toArray(): array
    {
        return ['id' => $this->review->id, 'booking_id' => $this->review->booking_id, 'rating' => (int) $this->review->rating, 'tag' => $this->review->tag, 'comments' => $this->review->comments, 'created_at' => $this->review->created_at?->toIso8601String()];
    }
}
