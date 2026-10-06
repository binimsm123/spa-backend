<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    protected $fillable = [
        'business_id',
        'category_id',
        'default_location_id',
        'name',
        'slug',
        'search_name',
        'description',
        'image_path',
        'duration_minutes',
        'max_people',
        'is_bookable',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_bookable' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'default_location_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(BusinessLocation::class, 'business_service_locations')
            ->withPivot('is_bookable')
            ->using(BusinessServiceLocation::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
