<?php

namespace App\Http\Dto\Api;

use App\Http\Resources\ServicePriceResource;

final readonly class AvailabilityData
{
    public function __construct(private string $providerId, private mixed $service, private string $timezone, private mixed $dates) {}

    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'service' => [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'description' => $this->service->description,
                'image_url' => $this->service->image_path ? url('storage/'.$this->service->image_path) : null,
                'price' => (int) (($this->service->price ?? 0) / 100),
                'currency' => $this->service->currency ?? 'NPR',
                'service_prices' => ServicePriceResource::collection($this->service->currentPrices)->resolve(),
            ],
            'timezone' => $this->timezone,
            'dates' => $this->dates,
        ];
    }
}
