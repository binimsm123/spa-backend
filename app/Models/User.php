<?php

namespace App\Models;

use App\Enums\BusinessUserRole;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable as BaseNotifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use BaseNotifiable, HasApiTokens, HasFactory, HasRoles, HasUlid, SoftDeletes;

    protected $fillable = [
        'mobile',
        'email',
        'password',
        'display_name',
        'image',
        'timezone',
        'address',
        'city',
        'latitude',
        'longitude',
        'is_active',
        'reward_points',
        'mobile_verified_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'reward_points' => 'integer',
            'mobile_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_users')
            ->withPivot(['role', 'is_active'])
            ->using(BusinessUser::class);
    }

    public function businessMemberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function activeBusinessMemberships(): HasMany
    {
        return $this->businessMemberships()->where('is_active', true);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function bookingRequests(): HasMany
    {
        return $this->hasMany(BookingRequest::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function referralCode(): HasOne
    {
        return $this->hasOne(ReferralCode::class);
    }

    public function referral(): HasOne
    {
        return $this->hasOne(Referral::class, 'referred_user_id');
    }

    public function rewardTransactions(): HasMany
    {
        return $this->hasMany(RewardPointTransaction::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    // -------------------------------------------------------------------------
    // Business membership helpers
    // -------------------------------------------------------------------------

    /**
     * The business_users pivot row for a business, if the membership is active.
     */
    public function membershipFor(Business $business): ?BusinessUser
    {
        /** @var BusinessUser|null $membership */
        $membership = $this->businessMemberships()
            ->where('business_id', $business->getKey())
            ->where('is_active', true)
            ->first();

        return $membership;
    }

    public function belongsToBusiness(Business $business): bool
    {
        return $this->membershipFor($business) !== null;
    }

    public function hasBusinessRole(Business $business, BusinessUserRole ...$roles): bool
    {
        $membership = $this->membershipFor($business);

        return $membership !== null && in_array($membership->role, array_map(
            static fn (BusinessUserRole $role) => $role->value,
            $roles,
        ), true);
    }

    /**
     * Active business IDs the user is a member of (owners/managers/staff).
     *
     * @return array<int, string>
     */
    public function businessIds(): array
    {
        return $this->activeBusinessMemberships()->pluck('business_id')->all();
    }

    // -------------------------------------------------------------------------
    // Reward points (ledger is the source of truth; this is the cached balance)
    // -------------------------------------------------------------------------

    public function addRewardPoints(int $delta): void
    {
        DB::table('users')
            ->where('id', $this->getKey())
            ->increment('reward_points', $delta);

        $this->reward_points = (int) $this->reward_points + $delta;
    }
}
