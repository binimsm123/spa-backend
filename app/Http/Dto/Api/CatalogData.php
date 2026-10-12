<?php

namespace App\Http\Dto\Api;

use App\Http\Resources\BusinessCatalogResource;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

final readonly class CatalogData
{
    public function __construct(
        private User $user,
        private LengthAwarePaginator|Collection $categories,
        private int $unreadCount,
    ) {}

    public function toArray(): array
    {
        $categories = $this->categories instanceof LengthAwarePaginator
            ? $this->categories->getCollection()
            : $this->categories;

        $data = [
            'user' => [
                'id' => $this->user->id,
                'display_name' => $this->user->display_name,
                'mobile_number' => $this->user->mobile,
                'roles' => $this->user->getRoleNames()->values()->all(),
                'avatar_url' => $this->user->image ? url('storage/'.$this->user->image) : null,
                'address' => $this->user->address,
                'city' => $this->user->city,
                'latitude' => $this->user->latitude !== null ? (float) $this->user->latitude : null,
                'longitude' => $this->user->longitude !== null ? (float) $this->user->longitude : null,
            ],
            'unread_notification_count' => $this->unreadCount,
            'banner' => null,
            'categories' => BusinessCatalogResource::categoryListing($categories),
        ];

        if ($this->categories instanceof LengthAwarePaginator) {
            $data['pagination'] = [
                'page' => $this->categories->currentPage(),
                'per_page' => $this->categories->perPage(),
                'total' => $this->categories->total(),
                'total_pages' => $this->categories->lastPage(),
                'has_more' => $this->categories->hasMorePages(),
            ];
        }

        return $data;
    }
}
