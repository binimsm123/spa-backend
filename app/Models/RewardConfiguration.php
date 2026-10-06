<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardConfiguration extends Model
{
    use HasUlid;

    protected $fillable = [
        'points_per_rupee',
        'basis',
        'is_active',
        'starts_at',
        'deactivated_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'points_per_rupee' => 'decimal:4',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
