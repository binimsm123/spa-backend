<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferRedemption extends Model
{
    use HasUlid;

    protected $fillable = [
        'offer_id',
        'user_id',
        'booking_id',
        'service_id',
        'idempotency_key',
        'discount_minor',
        'points_used',
        'expires_at',
        'redeemed_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'discount_minor' => 'integer',
            'points_used' => 'integer',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
