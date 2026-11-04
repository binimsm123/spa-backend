<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BusinessUser extends Pivot
{
    use HasUlid;

    public $incrementing = false;

    protected $table = 'business_users';

    protected $fillable = ['business_id', 'user_id', 'role', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'business_user_service', 'business_user_id', 'service_id')->withTimestamps();
    }

    public function hours(): HasMany
    {
        return $this->hasMany(StaffHour::class, 'business_user_id');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(StaffLeave::class, 'business_user_id');
    }
}
