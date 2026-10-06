<?php

namespace App\Models;

use App\Enums\BookingRequestStatus;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BookingRequest extends Model
{
    use HasUlid;

    protected $fillable = [
        'user_id',
        'business_id',
        'business_location_id',
        'service_id',
        'people_count',
        'requested_date',
        'timezone',
        'status',
        'idempotency_key',
        'expires_at',
        'accepted_at',
        'declined_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingRequestStatus::class,
            'requested_date' => 'date',
            'people_count' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function times(): HasMany
    {
        return $this->hasMany(BookingRequestTime::class);
    }

    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class);
    }
}
