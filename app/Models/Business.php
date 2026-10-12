<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Business extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    public const KYC_DOCUMENT_TYPES = [
        'vat_pan_document',
        'business_registration_document',
        'ward_registration_document',
        'municipality_registration_document',
        'owner_identity_front',
        'owner_identity_back',
        'nid_front',
    ];

    protected $hidden = ['kyc_documents'];

    protected $fillable = [
        'name',
        'slug',
        'about',
        'hero_image_path',
        'phone_number',
        'home_service',
        'location_service',
        'staff_number',
        'is_verified',
        'is_online',
        'status',
        'timezone',
    ];

    protected static function booted(): void
    {
        static::updating(function (Business $business): void {
            if ($business->getRawOriginal('is_verified') && $business->isDirty('kyc_documents')) {
                throw ValidationException::withMessages([
                    'kyc_documents' => 'KYC documents cannot be updated after business verification.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kyc_documents' => 'array',
            'is_verified' => 'boolean',
            'is_online' => 'boolean',
            'home_service' => 'boolean',
            'location_service' => 'boolean',
            'staff_number' => 'integer',
            'rating_average' => 'float',
        ];
    }

    public function kycSummary(string $documentRoute = 'business.documents.show'): array
    {
        return [
            'documents' => collect(self::KYC_DOCUMENT_TYPES)
                ->filter(fn (string $type): bool => isset($this->kyc_documents[$type]))
                ->map(fn (string $type): array => [
                    'type' => $type,
                    'download_url' => route($documentRoute, ['business' => $this->getKey(), 'document' => $type]),
                ])
                ->values()
                ->all(),
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class)->orderBy('id');
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

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'business_category')->using(BusinessCategory::class)->withTimestamps();
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
