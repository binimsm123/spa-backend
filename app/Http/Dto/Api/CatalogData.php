<?php

namespace App\Http\Dto\Api;

use App\Http\Resources\UserResource;
use App\Models\Category;
use App\Models\User;

final readonly class CatalogData
{
    public function __construct(
        private User $user,
        private mixed $location,
        private mixed $categories,
        private mixed $services,
        private int $unreadCount,
    ) {}

    public function toArray(): array
    {
        $location = $this->location;
        $services = collect($this->services);

        return [
            'user' => array_intersect_key((new UserResource($this->user))->resolve(), array_flip(['id', 'display_name', 'mobile_number', 'avatar_url'])),
            'unread_notification_count' => $this->unreadCount,
            'location' => $location ? ['id' => $location->id, 'label' => $location->name.', '.$location->city, 'latitude' => (float) $location->latitude, 'longitude' => (float) $location->longitude] : null,
            'banner' => null,
            'categories' => collect($this->categories)->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'services' => $services->where('category_id', $category->id)->map(fn ($service) => [
                    'id' => $service->id,
                    'provider_id' => $service->business_id,
                    'provider_name' => $service->business?->name,
                    'name' => $service->name,
                    'description' => $service->description,
                    'image_url' => $service->image_path ? url('storage/'.$service->image_path) : null,
                    'price' => (int) ($service->price ?? 0),
                    'currency' => $service->currency ?? 'NPR',
                    'duration_minutes' => (int) $service->duration_minutes,
                    'bookable' => (bool) $service->is_bookable,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
