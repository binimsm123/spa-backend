<?php

namespace App\Http\Dto\Api;

final readonly class OffersData
{
    public function __construct(private mixed $items, private int $page, private int $perPage, private int $total, private int $totalPages) {}

    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'pagination' => ['page' => $this->page, 'per_page' => $this->perPage, 'total' => $this->total, 'total_pages' => $this->totalPages],
        ];
    }
}
