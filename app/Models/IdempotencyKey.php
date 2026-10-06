<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    use HasUlid;

    protected $fillable = [
        'user_id',
        'operation',
        'key',
        'request_hash',
        'response_code',
        'response_json',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'response_json' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
