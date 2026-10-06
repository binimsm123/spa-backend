<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingRequestTime extends Model
{
    use HasUlid;

    protected $fillable = ['booking_request_id', 'starts_at', 'ends_at', 'is_available'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_available' => 'boolean',
        ];
    }

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }
}
