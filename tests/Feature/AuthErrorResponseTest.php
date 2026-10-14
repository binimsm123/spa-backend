<?php

use App\Models\RefreshToken;
use App\Models\User;

it('returns the authentication envelope for missing invalid expired or revoked access tokens regardless of Accept header', function (string $kind, bool $json): void {
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
            'message' => 'Authentication is required.',
            'code' => 'UNAUTHENTICATED',
        ]);
})->with(['missing', 'invalid', 'expired', 'revoked'])->with([false, true]);

it('rejects unauthenticated protected auth actions before payload validation without an Accept header', function (string $path): void {
    $this->post($path)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED')
        ->assertJsonPath('message', 'Authentication is required.');
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

it('returns UNAUTHENTICATED for newHome with a missing or invalid token without an Accept header', function (bool $invalid): void {
    $headers = $invalid ? ['Authorization' => 'Bearer invalid-access-token'] : [];

    $this->get('/api/v1/newHome', $headers)
        ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED')
        ->assertJsonPath('message', 'Authentication is required.');
})->with([false, true]);

it('loads newHome using a valid Bearer token without an Accept header', function (): void {
    $user = User::factory()->create(['mobile_verified_at' => now()]);
    $token = $user->createToken('new-home-test', ['*'], now()->addHour())->plainTextToken;

    $this->get('/api/v1/newHome', ['Authorization' => 'Bearer '.$token])
        ->assertOk()->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Home catalog loaded.')
        ->assertJsonCount(0, 'data.categories');
});
