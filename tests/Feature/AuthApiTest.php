<?php

use App\Models\User;

it('allows login with email or phone for each role', function (string $role, string $field): void {
    $user = User::factory()->create(['mobile_verified_at' => now(), 'is_active' => true]);
    $user->assignRole($role);

    $this->postJson('/api/v1/auth/login', [
        $field => $field === 'email' ? $user->email : $user->mobile,
        'password' => 'password',
    ])->assertOk()->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.roles', [$role])
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'expires_in']]);
})->with(['superadmin', 'customer'])->with(['email', 'mobile_number']);

it('rejects invalid or ambiguous login identifiers with 422', function (array $identifiers, array $fields): void {
    $this->postJson('/api/v1/auth/login', [...$identifiers, 'password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors($fields);
})->with([
    'missing identifier' => [[], ['email', 'mobile_number']],
    'malformed email' => [['email' => 'invalid'], ['email']],
    'both identifiers' => [['email' => 'admin@example.test', 'mobile_number' => '+9779800000000'], ['email', 'mobile_number']],
]);

it('rejects incorrect email credentials with 401 without creating tokens', function (bool $exists): void {
    if ($exists) {
        User::factory()->create(['email' => 'admin@example.test', 'mobile_verified_at' => now()]);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.test', 'password' => 'wrong-password'])
        ->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS')
        ->assertJsonPath('message', 'Invalid email or password.');
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([false, true]);

it('retains account status and mobile verification checks during email login', function (bool $active, bool $verified, string $code): void {
    $user = User::factory()->create(['is_active' => $active, 'mobile_verified_at' => $verified ? now() : null]);
    $user->assignRole('superadmin');

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertForbidden()->assertJsonPath('code', $code);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'disabled' => [false, true, 'ACCOUNT_DISABLED'],
    'unverified mobile' => [true, false, 'MOBILE_NOT_VERIFIED'],
]);

it('registers a customer and returns the success envelope', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'mobile_number' => '+9779811112222',
        'delivery_method' => 'sms',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['verification_id', 'delivery_method', 'masked_destination', 'expires_in']])
        ->assertJsonStructure(['success', 'message', 'data']);
});

it('rejects bad mobile numbers with the validation envelope', function (): void {
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

it('verifies registration and completes the account before issuing tokens', registerVerifyFlow());

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
        $mobile = '+97798'.random_int(10000000, 99999999);

        $register = $this->postJson('/api/v1/auth/register', [
            'mobile_number' => $mobile,
            'password' => 'Customer#1',
            'password_confirmation' => 'Customer#1',
            'display_name' => 'Verify Me',
        ])->assertCreated();

        $verificationId = $register->json('data.verification_id');
        $verify = $this->postJson('/api/v1/auth/register/verify', [
            'verification_id' => $verificationId,
            'code' => last_test_sms_code(),
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mobile_number', $mobile)
            ->assertJsonPath('data.mobile_verified', true)
            ->assertJsonStructure(['data' => ['registration_token', 'expires_in']]);

        $this->assertDatabaseMissing('users', ['mobile' => $mobile]);
        expect($verify->json('data'))->not->toHaveKeys(['access_token', 'refresh_token']);

        $complete = $this->postJson('/api/v1/auth/register/complete', [
            'registration_token' => $verify->json('data.registration_token'),
            'password' => 'Customer#1',
            'password_confirmation' => 'Customer#1',
        ])->assertCreated()->assertJsonPath('data.user.mobile_verified', true)
            ->assertJsonPath('data.referral_applied', false)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'user']]);

        $this->assertDatabaseHas('users', ['id' => $complete->json('data.user.id'), 'mobile' => $mobile]);
    };
}
