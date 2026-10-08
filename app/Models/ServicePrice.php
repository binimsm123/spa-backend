<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePrice extends Model
{
    use HasUlid;

    protected $fillable = [
        'service_id',
        'price_minor',
        'currency',
        'duration',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function durationMinutes(): int
    {
        [$hours, $minutes] = explode(':', (string) $this->duration);

        return ((int) $hours * 60) + (int) $minutes;
    }
}
