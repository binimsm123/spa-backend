<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessLocation extends Model
{
    use HasUlid;

    protected $fillable = ['address', 'city', 'latitude', 'longitude', 'service_availability'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
