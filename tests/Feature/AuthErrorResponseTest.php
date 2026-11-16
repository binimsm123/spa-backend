<?php

use App\Models\RefreshToken;
use App\Models\User;

it('returns a specific 401 message for missing invalid expired or revoked access tokens regardless of Accept header', function (string $kind, string $message, bool $json): void {
    $headers = $json ? ['Accept' => 'application/json'] : [];
    if ($kind === 'invalid') {
        $headers['Authorization'] = 'Bearer invalid-access-token';
    } elseif (in_array($kind, ['expired', 'revoked'], true)) {
        $user = User::factory()->create();
        $token = $user->createToken('auth-error-test', ['*'], $kind === 'expired' ? now()->subMinute() : now()->addHour());
        $headers['Authorization'] = 'Bearer '.$token->plainTextToken;
        if ($kind === 'revoked') {
            $token->accessToken->delete();
        }
    }

    $this->get('/api/v1/users/me', $headers)
        ->assertUnauthorized()
        ->assertExactJson([
            'success' => false,
            'message' => $message,
            'code' => 'UNAUTHENTICATED',
        ]);
})->with([
    'missing' => ['missing', 'Authentication token is missing. Please provide a Bearer token.'],
    'invalid' => ['invalid', 'Authentication token is invalid. Please log in again.'],
    'expired' => ['expired', 'Authentication token has expired. Please log in again.'],
    'revoked' => ['revoked', 'Authentication token is invalid. Please log in again.'],
])->with([false, true]);

it('rejects unauthenticated protected auth actions before payload validation without an Accept header', function (string $path): void {
    $this->post($path)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED')
        ->assertJsonPath('message', 'Authentication token is missing. Please provide a Bearer token.');
})->with(['/api/v1/auth/logout', '/api/v1/auth/password/change']);

it('returns an auth error for an invalid expired revoked or deleted account reset token', function (string $kind): void {
    $plain = str_repeat('r', 60);
    if ($kind !== 'invalid') {
        $user = User::factory()->create();
        RefreshToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $kind === 'expired' ? now()->subMinute() : now()->addHour(),
            'revoked_at' => $kind === 'revoked' ? now() : null,
        ]);
        if ($kind === 'deleted account') {
            $user->delete();
        }
    }

    $this->postJson('/api/v1/auth/password/reset', [
        'reset_token' => $plain,
        'password' => 'ResetPassword@123',
        'password_confirmation' => 'ResetPassword@123',
    ])->assertUnauthorized()->assertExactJson([
        'success' => false,
        'message' => 'Password reset token is invalid or expired.',
        'code' => 'INVALID_RESET_TOKEN',
    ]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['invalid', 'expired', 'revoked', 'deleted account']);

it('rejects refresh tokens belonging to deleted accounts with an auth error', function (): void {
    $user = User::factory()->create();
    $plain = str_repeat('f', 60);
    RefreshToken::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $plain),
        'expires_at' => now()->addHour(),
    ]);
    $user->delete();

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $plain])
        ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED')
        ->assertJsonPath('message', 'Refresh token is invalid or expired.');
});

it('continues to return NOT_FOUND for unknown routes', function (): void {
    $this->get('/api/v1/this-route-does-not-exist')
        ->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
});

it('returns 401 for home with a missing or invalid token without an Accept header', function (bool $invalid): void {
    $headers = $invalid ? ['Authorization' => 'Bearer invalid-access-token'] : [];

    $this->get('/api/v1/home', $headers)
        ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED')
        ->assertJsonPath('message', $invalid
            ? 'Authentication token is invalid. Please log in again.'
            : 'Authentication token is missing. Please provide a Bearer token.');
})->with([false, true]);

it('loads home using a valid Bearer token without an Accept header', function (): void {
    $user = User::factory()->create(['mobile_verified_at' => now()]);
    $token = $user->createToken('new-home-test', ['*'], now()->addHour())->plainTextToken;

    $this->get('/api/v1/home', ['Authorization' => 'Bearer '.$token])
        ->assertOk()->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Home catalog loaded.')
        ->assertJsonCount(0, 'data.categories');
});

it('returns specific 401 messages for missing invalid and expired tokens on business endpoints', function (string $kind, string $message): void {
    $headers = [];
    if ($kind === 'invalid') {
        $headers['Authorization'] = 'Bearer invalid-access-token';
    } elseif ($kind === 'expired') {
        $user = User::factory()->create();
        $headers['Authorization'] = 'Bearer '.$user->createToken('business-auth-test', ['*'], now()->subMinute())->plainTextToken;
    }

    $this->get('/api/v1/business/my', $headers)->assertUnauthorized()->assertExactJson([
        'success' => false, 'message' => $message, 'code' => 'UNAUTHENTICATED',
    ]);
})->with([
    'missing' => ['missing', 'Authentication token is missing. Please provide a Bearer token.'],
    'invalid' => ['invalid', 'Authentication token is invalid. Please log in again.'],
    'expired' => ['expired', 'Authentication token has expired. Please log in again.'],
]);

it('returns 401 for malformed or empty authorization headers', function (string $header, string $message): void {
    $this->get('/api/v1/business/my', ['Authorization' => $header])
        ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED')->assertJsonPath('message', $message);
})->with([
    'empty header' => ['', 'Authentication token is missing. Please provide a Bearer token.'],
    'empty Bearer' => ['Bearer ', 'Authentication token is missing. Please provide a Bearer token.'],
    'Bearer scheme only' => ['Bearer', 'Authentication token is missing. Please provide a Bearer token.'],
    'unsupported scheme' => ['Basic invalid-access-token', 'Authentication token is invalid. Please log in again.'],
    'missing secret' => ['Bearer 1|', 'Authentication token is invalid. Please log in again.'],
    'invalid token identifier' => ['Bearer invalid|secret', 'Authentication token is invalid. Please log in again.'],
]);

it('returns 401 with an expired message when the configured token lifetime is exceeded', function (bool $perTokenExpiry): void {
    $this->freezeTime();
    config(['sanctum.expiration' => 60]);
    $user = User::factory()->create();
    $token = $user->createToken('global-expiry-test', ['*'], $perTokenExpiry ? now()->addDay() : null);
    $token->accessToken->forceFill(['created_at' => now()->subMinutes(60)])->save();

    $this->get('/api/v1/business/my', ['Authorization' => 'Bearer '.$token->plainTextToken])
        ->assertUnauthorized()->assertJsonPath('message', 'Authentication token has expired. Please log in again.');
})->with([false, true]);

it('returns 401 with an invalid message when the secret or identifier of an expired token is tampered with', function (bool $invalidIdentifier): void {
    $user = User::factory()->create();
    $token = $user->createToken('tampered-expired-token', ['*'], now()->subMinute());
    [$id, $secret] = explode('|', $token->plainTextToken, 2);
    $plainToken = $invalidIdentifier ? $id.'invalid|'.$secret : $id.'|invalid-secret';

    $this->get('/api/v1/business/my', ['Authorization' => 'Bearer '.$plainToken])
        ->assertUnauthorized()->assertJsonPath('message', 'Authentication token is invalid. Please log in again.');
})->with([false, true]);

it('continues to accept valid unexpired tokens on business routes', function (bool $perTokenExpiry): void {
    $this->freezeTime();
    config(['sanctum.expiration' => 60]);
    $user = User::factory()->create();
    $token = $user->createToken('valid-business-token', ['*'], $perTokenExpiry ? now()->addHour() : null);

    $this->get('/api/v1/business/my', ['Authorization' => 'Bearer '.$token->plainTextToken])->assertOk();
})->with([false, true]);

it('continues to return 403 when a valid token lacks business membership', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('non-member-token', ['*'], now()->addHour());
    [$business] = createBusinessWithService();

    $this->get('/api/v1/business/'.$business->getKey(), ['Authorization' => 'Bearer '.$token->plainTextToken])
        ->assertForbidden()->assertJsonPath('message', 'You do not manage this business.');
});
