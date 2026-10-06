<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePrice extends Model
{
    use HasUlid;

    protected $fillable = [
        'service_id',
        'business_location_id',
        'price_minor',
        'currency',
        'starts_at',
        'ends_at',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'is_current' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }
}
