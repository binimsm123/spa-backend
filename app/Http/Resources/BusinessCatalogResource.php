<?php

namespace App\Http\Resources;

use App\Models\Business;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class BusinessCatalogResource extends BusinessResource
{
    /** @param Collection<int, Category> $categories */
    public static function categoryListing(Collection $categories): array
    {
        return $categories->map(function (Category $category): array {
            $data = [
                'id' => $category->id,
                'name' => $category->name,
                'sort_order' => (int) $category->sort_order,
                'businesses' => $category->businesses->map(function (Business $business): array {
                    if (array_key_exists('services_page', $business->getAttributes())) {
                        $data = (new self($business))->resolve();
                        $data['services'] = $data['categories'][0]['services'];
                    } else {
                        $data = array_intersect_key((new BusinessResource($business))->resolve(), array_flip([
                            ...array_keys($business->getAttributes()),
                            'hero_image_url', 'locations', 'services',
                        ]));
                        unset($data['address'], $data['city'], $data['latitude'], $data['longitude']);
                        if (array_key_exists('phone_number', $business->getAttributes())) {
                            $data['phone_number'] = $business->phone_number;
                        }
                        if ($business->relationLoaded('services')) {
                            $data['services'] = (new self($business))->serializeServices($business->services);
                        }
                        if (array_key_exists('distance_km', $business->getAttributes())) {
                            $data['distance_km'] = $business->distance_km;
                        }
                    }
                    unset($data['categories']);

                    return $data;
                })->values()->all(),
            ];
            if (! array_key_exists('sort_order', $category->getAttributes())) {
                unset($data['sort_order']);
            }
            if (! array_key_exists('businesses_page', $category->getAttributes()) && array_key_exists('slug', $category->getAttributes())) {
                $data['slug'] = $category->slug;
            }
            if (array_key_exists('businesses_page', $category->getAttributes())) {
                $data['pagination'] = [
                    'page' => (int) $category->businesses_page,
                    'per_page' => (int) $category->businesses_per_page,
                    'total' => (int) $category->businesses_count,
                    'total_pages' => max(1, (int) ceil($category->businesses_count / $category->businesses_per_page)),
                    'has_more' => $category->businesses_page * $category->businesses_per_page < $category->businesses_count,
                ];
            }

            return $data;
        })->all();
    }

    /** @param Collection<int, Business> $businesses */
    public static function groupedCategories(Collection $businesses): array
    {
        $categories = [];
        foreach ($businesses as $business) {
            $data = (new self($business))->resolve();
            $groups = $data['categories'];
            unset($data['categories']);
            foreach ($groups as $group) {
                $key = $group['id'] ?? 'uncategorized';
                if (! isset($categories[$key])) {
                    $categories[$key] = [
                        'id' => $group['id'], 'name' => $group['name'],
                        'sort_order' => $group['sort_order'], 'businesses' => [],
                    ];
                }
                $categories[$key]['businesses'][] = $data + ['services' => $group['services']];
            }
        }

        return collect($categories)->sortBy('sort_order')->values()->all();
    }

    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        unset($data['services']);
        $servicesByCategory = $this->services->groupBy(fn ($service) => $service->category_id ?? 'uncategorized');
        $groups = $this->categories->map(fn ($category): array => [
            'id' => $category->id, 'name' => $category->name, 'sort_order' => (int) $category->sort_order,
            'services' => $this->serializeServices($servicesByCategory->get($category->id ?? 'uncategorized', collect())),
        ]);
        if ($servicesByCategory->has('uncategorized') && ! $groups->contains('id', null)) {
            $groups->push([
                'id' => null, 'name' => 'Uncategorized', 'sort_order' => 0,
                'services' => $this->serializeServices($servicesByCategory->get('uncategorized')),
            ]);
        }
        $data['categories'] = $groups->sortBy('sort_order')->values()->all();
        $data['services_count'] = (int) $this->services_count;
        $data['services_has_more'] = $this->services_page * $this->services_per_page < $this->services_count;
        $data['services_pagination'] = [
            'page' => (int) $this->services_page, 'per_page' => (int) $this->services_per_page,
            'total' => (int) $this->services_count,
            'total_pages' => (int) ceil($this->services_count / $this->services_per_page),
            'has_more' => $data['services_has_more'],
        ];
        $data['distance_km'] = $this->whenHas('distance_km');

        return $data;
    }

    private function serializeServices(\Illuminate\Support\Collection $services): array
    {
        return $services->map(function ($service): array {
            $price = $service->currentPrices->first();

            return [
                'id' => $service->id, 'name' => $service->name, 'description' => $service->description,
                'image_url' => $service->image_path ? url('storage/'.$service->image_path) : null,
                'price' => (int) ($price?->price_minor ?? 0), 'currency' => $price?->currency ?? 'NPR',
                'service_prices' => ServicePriceResource::collection($service->currentPrices)->resolve(),
                'bookable' => (bool) $service->is_bookable,
            ];
        })->values()->all();
    }
}
