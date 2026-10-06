<?php

namespace App\Models;

use App\Enums\PaymentGateway;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasUlid;

    protected $fillable = [
        'booking_id',
        'user_id',
        'gateway',
        'gateway_reference',
        'purpose',
        'amount_minor',
        'currency',
        'status',
        'checkout_url',
        'gateway_payload',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'gateway' => PaymentGateway::class,
            'purpose' => PaymentPurpose::class,
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'gateway_payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tip(): HasOne
    {
        return $this->hasOne(Tip::class);
    }
}
