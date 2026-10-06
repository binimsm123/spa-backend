<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BusinessServiceLocation extends Pivot
{
    use HasUlid;

    public $incrementing = false;

    protected $table = 'business_service_locations';

    protected $fillable = ['service_id', 'business_location_id', 'is_bookable'];

    protected function casts(): array
    {
        return [
            'is_bookable' => 'boolean',
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
