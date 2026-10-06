<?php

use App\Models\User;

it('matches the collection contract for account creation', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'mobile_number' => '+9779812345678',
        'password' => 'Example@123',
        'password_confirmation' => 'Example@123',
        'delivery_method' => 'sms',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Verification code sent.')
        ->assertJsonStructure([
            'success', 'message',
            'data' => [
                'verification_id', 'delivery_method', 'masked_destination',
                'expires_in', 'referral_applied',
            ],
        ]);

    expect($response->json('data'))->not->toHaveKey('user_id');
});

it('matches the collection contract for login', function (): void {
    $user = User::factory()->create([
        'mobile' => '+9779812345678',
        'password' => 'Example@123',
        'mobile_verified_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'mobile_number' => $user->mobile,
        'password' => 'Example@123',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Logged in successfully.')
        ->assertJsonStructure([
            'data' => [
                'user' => ['id', 'mobile_number', 'mobile_verified', 'created_at'],
                'access_token', 'refresh_token', 'token_type', 'expires_in',
            ],
        ]);
});

it('uses the collection contract for password change and logout messages', function (): void {
    [$user, $tokens] = registerCustomer();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Customer#1',
            'password' => 'NewPassword#9',
            'password_confirmation' => 'NewPassword#9',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Password changed successfully.')
        ->assertJsonStructure(['data' => ['changed_at', 'other_sessions_revoked']]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/logout', ['refresh_token' => $tokens['refresh_token']])
        ->assertOk()
        ->assertJsonPath('message', 'Logged out successfully.')
        ->assertJsonPath('data', null);
});
