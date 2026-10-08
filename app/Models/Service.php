<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    protected $fillable = [
        'business_id',
        'category_id',
        'name',
        'slug',
        'search_name',
        'description',
        'image_path',
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

    public function prices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    public function currentPrices(): HasMany
    {
        return $this->prices()->where('is_current', true);
    }

    public function currentPrice(): HasOne
    {
        return $this->hasOne(ServicePrice::class)
            ->where('is_current', true);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
