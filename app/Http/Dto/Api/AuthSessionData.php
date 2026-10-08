<?php

namespace App\Http\Dto\Api;

use App\Http\Resources\UserResource;
use App\Models\User;

/**
 * Response data for login and registration verification.
 * This is deliberately separate from the User model/resource so the public
 * contract cannot accidentally expose internal user fields.
 */
final readonly class AuthSessionData
{
    public function __construct(
        public User $user,
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn = 3600,
    ) {}

    public function toArray(): array
    {
        $user = (new UserResource($this->user))->resolve();

        return [
            'user' => array_intersect_key($user, array_flip([
                'id', 'mobile_number', 'mobile_verified', 'created_at', 'roles',
            ])),
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->expiresIn,
        ];
    }
}
