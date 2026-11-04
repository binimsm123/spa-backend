<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffLeave extends Model
{
    use HasFactory, HasUlid;

    protected $fillable = ['business_user_id', 'starts_on', 'ends_on', 'starts_at', 'ends_at', 'reason'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'starts_at' => 'datetime:H:i',
            'ends_at' => 'datetime:H:i',
        ];
    }

    public function businessMembership(): BelongsTo
    {
        return $this->belongsTo(BusinessUser::class, 'business_user_id');
    }
}
