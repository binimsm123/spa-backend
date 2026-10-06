<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLocation extends Model
{
    use HasUlid;

    protected $fillable = ['user_id', 'location_id', 'is_current', 'selected_at'];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'selected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
