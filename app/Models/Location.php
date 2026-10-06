<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory, HasUlid;

    protected $fillable = [
        'region',
        'name',
        'city',
        'slug',
        'latitude',
        'longitude',
        'is_serviceable',
    ];

    protected function casts(): array
    {
        return [
            'is_serviceable' => 'boolean',
        ];
    }

    public function businessLocations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class);
    }
}
