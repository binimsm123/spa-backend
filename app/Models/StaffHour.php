<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffHour extends Model
{
    use HasFactory, HasUlid;

    protected $fillable = ['business_user_id', 'weekday', 'opens_at', 'closes_at', 'is_closed'];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'opens_at' => 'datetime:H:i',
            'closes_at' => 'datetime:H:i',
            'is_closed' => 'boolean',
        ];
    }

    public function businessMembership(): BelongsTo
    {
        return $this->belongsTo(BusinessUser::class, 'business_user_id');
    }
}
