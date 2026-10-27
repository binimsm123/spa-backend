<?php

namespace App\Models;

use App\Enums\VerificationType;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationCode extends Model
{
    use HasUlid;

    protected $fillable = [
        'user_id',
        'mobile_number',
        'reset_token_hash',
        'reset_token_expires_at',
        'registration_token_hash',
        'registration_token_expires_at',
        'type',
        'code',
        'expires_at',
        'attempts',
        'resent_count',
        'last_sent_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => VerificationType::class,
            'registration_token_expires_at' => 'datetime',
            'reset_token_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'attempts' => 'integer',
            'resent_count' => 'integer',
            'last_sent_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
