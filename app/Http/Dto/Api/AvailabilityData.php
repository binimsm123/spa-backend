<?php

namespace App\Http\Dto\Api;

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
                'duration_minutes' => (int) $this->service->duration_minutes,
            ],
            'timezone' => $this->timezone,
            'dates' => $this->dates,
        ];
    }
}
