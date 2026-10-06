<?php

namespace App\Models;

use App\Enums\RewardTransactionType;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardPointTransaction extends Model
{
    public $timestamps = false;

    use HasUlid;

    protected $fillable = [
        'user_id',
        'booking_id',
        'offer_redemption_id',
        'reward_configuration_id',
        'type',
        'points',
        'balance_after',
        'description',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => RewardTransactionType::class,
            'points' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'datetime',
        ];
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
