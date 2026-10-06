<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralReward extends Model
{
    use HasUlid;

    protected $fillable = [
        'referral_id',
        'user_id',
        'reward_type',
        'value',
        'currency',
        'status',
        'earned_at',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'earned_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
