<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use HasUlid;

    protected $fillable = [
        'booking_request_id',
        'user_id',
        'business_id',
        'assigned_staff_user_id',
        'appointment_date',
        'starts_at',
        'ends_at',
        'timezone',
        'status',
        'people_count',
        'subtotal_minor',
        'tax_minor',
        'discount_minor',
        'tip_minor',
        'total_minor',
        'reward_points_earned',
        'currency',
        'confirmed_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'appointment_date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'people_count' => 'integer',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'discount_minor' => 'integer',
            'tip_minor' => 'integer',
            'total_minor' => 'integer',
            'reward_points_earned' => 'integer',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function successfulPayment(): HasOne
    {
        return $this->hasOne(Payment::class)
            ->where('purpose', 'booking')
            ->whereIn('status', ['succeeded']);
    }

    public function quote(): HasOne
    {
        return $this->hasOne(CheckoutQuote::class)->latestOfMany();
    }

    public function offerRedemption(): HasOne
    {
        return $this->hasOne(OfferRedemption::class)->where('status', 'applied');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function tips(): HasMany
    {
        return $this->hasMany(Tip::class);
    }

    public function rewardTransactions(): HasMany
    {
        return $this->hasMany(RewardPointTransaction::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === BookingStatus::Completed && $this->completed_at !== null;
    }
}
