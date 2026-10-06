<?php

namespace App\Http\Dto\Api;

use App\Models\User;

final readonly class ProfileData
{
    public function __construct(private User $user) {}

    public function toArray(): array
    {
        return [
            'id' => $this->user->getKey(),
            'display_name' => $this->user->display_name,
            'email' => $this->user->email,
            'mobile_number' => $this->user->mobile,
            'mobile_verified' => $this->user->mobile_verified_at !== null,
            'avatar_url' => $this->user->image ? url('storage/'.$this->user->image) : null,
            'updated_at' => $this->user->updated_at?->toIso8601String(),
        ];
    }
}
