<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutQuote extends Model
{
    use HasUlid;

    protected $fillable = [
        'booking_id',
        'offer_id',
        'subtotal_minor',
        'tax_minor',
        'tip_minor',
        'discount_minor',
        'total_minor',
        'currency',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'tip_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
