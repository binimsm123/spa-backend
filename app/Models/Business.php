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

    public const KYC_DOCUMENT_TYPES = [
        'vat_pan_document',
        'business_registration_document',
        'local_registration_document',
        'owner_identity_front',
        'owner_identity_back',
    ];

    protected $hidden = ['kyc_documents'];

    protected $fillable = [
        'name',
        'slug',
        'about',
        'hero_image_path',
        'phone_number',
        'is_verified',
        'is_online',
        'status',
        'address',
        'city',
        'latitude',
        'longitude',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'kyc_documents' => 'array',
            'is_verified' => 'boolean',
            'is_online' => 'boolean',
            'rating_average' => 'float',
        ];
    }

    public function kycSummary(): array
    {
        return [
            'documents' => array_keys($this->kyc_documents ?? []),
        ];
    }

    public function hours(): HasMany
    {
        return $this->hasMany(BusinessHour::class);
    }

    public function closures(): HasMany
    {
        return $this->hasMany(BusinessClosure::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
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
