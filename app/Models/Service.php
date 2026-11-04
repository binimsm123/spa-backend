<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    protected $attributes = [
        'max_people' => 1,
        'is_bookable' => true,
        'status' => 'active',
    ];

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

    protected static function booted(): void
    {
        static::created(fn (Service $service) => $service->linkCategory());
        static::updated(function (Service $service): void {
            if ($service->wasChanged(['category_id', 'business_id'])) {
                $service->linkCategory();
                $service->unsetRelation('category');
            }
        });
    }

    private function linkCategory(): void
    {
        if ($this->category_id === null) {
            return;
        }

        BusinessCategory::query()->insertOrIgnore([
            'business_id' => $this->business_id,
            'category_id' => $this->category_id,
            'created_at' => $this->updated_at,
            'updated_at' => $this->updated_at,
        ]);
    }

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

    public function staffMemberships(): BelongsToMany
    {
        return $this->belongsToMany(BusinessUser::class, 'business_user_service', 'service_id', 'business_user_id')->withTimestamps();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ServicePhoto::class)->orderBy('position')->orderBy('id');
    }

    public function currentPrices(): HasMany
    {
        return $this->prices()->where('is_current', true)->orderBy('duration')->orderBy('id');
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
