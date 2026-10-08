<?php

namespace App\Http\Dto\Api;

use App\Http\Resources\ServicePriceResource;
use App\Http\Resources\UserResource;
use App\Models\Category;
use App\Models\User;

final readonly class CatalogData
{
    public function __construct(
        private User $user,
        private mixed $categories,
        private mixed $services,
        private int $unreadCount,
    ) {}

    public function toArray(): array
    {
        $servicesByCategory = collect($this->services)->groupBy('category_id');

        return [
            'user' => array_intersect_key((new UserResource($this->user))->resolve(), array_flip(['id', 'display_name', 'mobile_number', 'avatar_url'])),
            'unread_notification_count' => $this->unreadCount,
            'location' => [
                'address' => $this->user->address,
                'city' => $this->user->city,
                'latitude' => $this->user->latitude !== null ? (float) $this->user->latitude : null,
                'longitude' => $this->user->longitude !== null ? (float) $this->user->longitude : null,
            ],
            'banner' => null,
            'categories' => collect($this->categories)->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'services' => $servicesByCategory->get($category->id, collect())->map(fn ($service) => [
                    'id' => $service->id,
                    'provider_id' => $service->business_id,
                    'provider_name' => $service->business?->name,
                    'name' => $service->name,
                    'description' => $service->description,
                    'image_url' => $service->image_path ? url('storage/'.$service->image_path) : null,
                    'price' => (int) ($service->price ?? 0),
                    'currency' => $service->currency ?? 'NPR',
                    'service_prices' => ServicePriceResource::collection($service->currentPrices)->resolve(),
                    'bookable' => (bool) $service->is_bookable,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
