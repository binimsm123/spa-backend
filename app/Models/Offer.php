<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OfferStatus;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Offer extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    public const SUMMARY_RELATIONS = [
        'business:id,name',
        'service:id,name',
        'service.currentPrices:service_id,price_minor,currency,duration',
    ];

    protected $fillable = [
        'business_id',
        'service_id',
        'created_by_user_id',
        'is_platform_sponsored',
        'code',
        'title',
        'description',
        'image_urls',
        'discount_type',
        'discount_value',
        'minimum_booking_amount_minor',
        'currency',
        'max_discount_minor',
        'starts_at',
        'expires_at',
        'status',
        'total_usage_limit',
        'per_user_limit',
        'usage_count',
        'requires_reward_points',
        'reward_points_cost',
    ];

    protected function casts(): array
    {
        return [
            'image_urls' => 'array',
            'discount_type' => DiscountType::class,
            'status' => OfferStatus::class,
            'is_platform_sponsored' => 'boolean',
            'requires_reward_points' => 'boolean',
            'discount_value' => 'integer',
            'minimum_booking_amount_minor' => 'integer',
            'max_discount_minor' => 'integer',
            'total_usage_limit' => 'integer',
            'per_user_limit' => 'integer',
            'usage_count' => 'integer',
            'reward_points_cost' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    #[Scope]
    protected function withListingDetails(Builder $query): void
    {
        $query->with(self::SUMMARY_RELATIONS);
    }

    #[Scope]
    protected function applicableToBusiness(Builder $query, string $businessId): void
    {
        $query->where(function (Builder $offers) use ($businessId): void {
            $offers->where('business_id', $businessId)
                ->orWhere(function (Builder $platform) use ($businessId): void {
                    $platform->whereNull('business_id')->where('is_platform_sponsored', true)
                        ->where(function (Builder $services) use ($businessId): void {
                            $services->whereNull('service_id')
                                ->orWhereHas('service', fn (Builder $service) => $service->where('business_id', $businessId));
                        });
                });
        });
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(OfferRedemption::class);
    }

    /**
     * True when the offer window covers the given time.
     */
    public function isActiveAt(\DateTimeInterface $at): bool
    {
        return $this->status === OfferStatus::Active
            && $this->starts_at <= $at
            && ($this->expires_at === null || $at <= $this->expires_at);
    }
}
