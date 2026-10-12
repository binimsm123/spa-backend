<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class BusinessLocationService
{
    /**
     * @param  array<int, array<string, mixed>>  $locations
     * @return array{location_service: bool, home_service: bool}
     */
    public function serviceFlags(array $locations): array
    {
        $availability = array_column($locations, 'service_availability');

        return [
            'location_service' => in_array('on_site', $availability, true),
            'home_service' => in_array('home', $availability, true),
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(Business $business, array $data): void
    {
        if (array_key_exists('locations', $data)) {
            $validated = Validator::make(Arr::only($data, ['locations']), [
                'locations' => ['required', 'array', 'min:1'],
                'locations.*' => ['required', 'array:address,city,latitude,longitude,service_availability'],
                'locations.*.address' => ['required', 'string', 'max:255'],
                'locations.*.city' => ['required', 'string', 'max:120'],
                'locations.*.latitude' => ['required', 'numeric', 'between:-90,90'],
                'locations.*.longitude' => ['required', 'numeric', 'between:-180,180'],
                'locations.*.service_availability' => ['required', 'in:home,on_site'],
            ])->validate();

            if (! $business->wasRecentlyCreated) {
                $business->locations()->delete();
            }
            $business->locations()->createMany($validated['locations']);

            $business->fill($this->serviceFlags($validated['locations']))->save();

            return;
        }

        $attributes = Arr::only($data, ['address', 'city', 'latitude', 'longitude']);
        if ($attributes === []) {
            return;
        }

        $location = $business->wasRecentlyCreated ? null : $business->locations()->first();
        if ($location !== null) {
            $location->update($attributes);
        } else {
            $business->locations()->create($attributes + [
                'service_availability' => ($data['home_service'] ?? false) && ! ($data['location_service'] ?? true) ? 'home' : 'on_site',
            ]);
        }
    }
}
