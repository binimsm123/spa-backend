<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingItem extends Model
{
    use HasUlid;

    protected $fillable = [
        'booking_id',
        'service_id',
        'service_name_snapshot',
        'duration_minutes_snapshot',
        'max_people_snapshot',
        'unit_price_minor',
        'currency',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes_snapshot' => 'integer',
            'max_people_snapshot' => 'integer',
            'unit_price_minor' => 'integer',
            'quantity' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
