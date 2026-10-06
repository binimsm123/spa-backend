<?php

it('registers a customer and returns the success envelope', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'mobile_number' => '+9779811112222',
        'password' => 'Customer#1',
        'password_confirmation' => 'Customer#1',
        'display_name' => 'Envelope Test',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['verification_id', 'delivery_method', 'masked_destination', 'expires_in', 'referral_applied']])
        ->assertJsonStructure(['success', 'message', 'data']);
});

it('rejects weak passwords and bad mobile numbers with the validation envelope', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'mobile_number' => 'not-a-phone',
        'password' => 'weak',
        'display_name' => 'X',
    ])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors']);
});

it('verifies registration and issues tokens', registerVerifyFlow());

it('rejects invalid login credentials with 401', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'mobile_number' => '+9779800000000',
        'password' => 'wrong-password',
    ])->assertStatus(401)->assertJsonPath('success', false);
});

it('rotates refresh tokens and rejects reuse of the old token', function (): void {
    [, $tokens] = registerCustomer();

    $refresh = $tokens['refresh_token'];

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);

    // Old refresh token must now be invalid (rotation).
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
        ->assertStatus(401);
});

it('logs out and revokes the refresh session', function (): void {
    [$user, $tokens] = registerCustomer();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/logout', ['refresh_token' => $tokens['refresh_token']])
        ->assertOk();

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
        ->assertStatus(401);
});

it('changes password and revokes other sessions', function (): void {
    [$user, $tokens] = registerCustomer();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Customer#1',
            'password' => 'NewPassword#9',
            'password_confirmation' => 'NewPassword#9',
        ])->assertOk();

    // Other sessions were revoked: the old refresh token no longer works.
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
        ->assertStatus(401);
});

it('completes password reset using reset_id and reset_token', function (): void {
    [$user] = registerCustomer();

    $forgot = $this->postJson('/api/v1/auth/password/forgot', [
        'mobile_number' => $user->mobile,
        'delivery_method' => 'sms',
    ])->assertOk()->assertJsonStructure(['data' => ['reset_id', 'masked_destination', 'expires_in']]);

    $resetId = $forgot->json('data.reset_id');

    $this->postJson('/api/v1/auth/password/resend', ['reset_id' => $resetId])
        ->assertStatus(429);

    $this->travel(61)->seconds();

    $resend = $this->postJson('/api/v1/auth/password/resend', ['reset_id' => $resetId])
        ->assertOk()->assertJsonStructure(['data' => ['reset_id', 'masked_destination', 'expires_in', 'resend_available_in']]);

    $verify = $this->postJson('/api/v1/auth/password/verify', [
        'reset_id' => $resend->json('data.reset_id'),
        'code' => config('spa.auth.static_verification_code', '1234'),
    ])->assertOk()->assertJsonStructure(['data' => ['reset_token', 'expires_in']]);

    $this->postJson('/api/v1/auth/password/reset', [
        'reset_token' => $verify->json('data.reset_token'),
        'password' => 'ResetPassword#9',
        'password_confirmation' => 'ResetPassword#9',
    ])->assertOk()->assertJsonPath('data', null);

    $this->postJson('/api/v1/auth/login', [
        'mobile_number' => $user->mobile,
        'password' => 'ResetPassword#9',
    ])->assertOk();
});

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function registerVerifyFlow(): Closure
{
    return function (): void {
        $mobile = '+97798'.random_int(1000000, 9999999);

        $register = $this->postJson('/api/v1/auth/register', [
            'mobile_number' => $mobile,
            'password' => 'Customer#1',
            'password_confirmation' => 'Customer#1',
            'display_name' => 'Verify Me',
        ])->assertCreated();

        $verificationId = $register->json('data.verification_id');
        $verification = \App\Models\VerificationCode::query()->findOrFail($verificationId);
        $userId = $verification->user_id;
        $user = \App\Models\User::query()->findOrFail($userId);
        $code = last_test_sms_code();

        $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $verificationId, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'user']]);

        expect($user->fresh()->mobile_verified_at)->not->toBeNull();
    };
}
