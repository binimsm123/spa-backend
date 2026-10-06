<?php

namespace App\Http\Dto\Api;

final readonly class TokenData
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn = 3600,
    ) {}

    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->expiresIn,
        ];
    }
}
