<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'search_name',
        'about',
        'hero_image_path',
        'phone_number',
        'is_verified',
        'is_insured',
        'is_online',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_insured' => 'boolean',
            'is_online' => 'boolean',
            'rating_average' => 'float',
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class);
    }

    public function activeLocations(): HasMany
    {
        return $this->locations()->where('is_active', true);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_users')
            ->withPivot(['role', 'is_active'])
            ->using(BusinessUser::class);
    }
}
