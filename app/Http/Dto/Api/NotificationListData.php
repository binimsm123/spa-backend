<?php

namespace App\Http\Dto\Api;

final readonly class NotificationListData
{
    public function __construct(private mixed $items, private int $page, private int $perPage, private int $total, private bool $hasMore) {}

    public function toArray(): array
    {
        return ['items' => $this->items, 'pagination' => ['page' => $this->page, 'per_page' => $this->perPage, 'total' => $this->total, 'has_more' => $this->hasMore]];
    }
}
