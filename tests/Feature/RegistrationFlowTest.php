<?php

use App\Enums\VerificationType;
use App\Models\ReferralCode;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

function pendingRegistration(): string
{
    return test()->postJson('/api/v1/auth/register', [
        'mobile_number' => '+9779812345678',
        'delivery_method' => 'sms',
    ])->assertCreated()->json('data.verification_id');
}

function verifiedRegistration(): string
{
    return test()->postJson('/api/v1/auth/register/verify', [
        'verification_id' => pendingRegistration(),
        'code' => last_test_sms_code(),
    ])->assertOk()->json('data.registration_token');
}

function completionPayload(string $token): array
{
    return [
        'registration_token' => $token,
        'password' => 'Example@123',
        'password_confirmation' => 'Example@123',
    ];
}

it('starts registration without creating an account or session and sends the SMS', function (): void {
    $this->freezeTime();
    $id = pendingRegistration();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('refresh_tokens', 0);
    $this->assertDatabaseHas('verification_codes', ['id' => $id, 'user_id' => null]);
    Http::assertSent(fn ($request) => $request['to'] === '9812345678'
        && str_contains($request['text'], last_test_sms_code()));
    $this->postJson('/api/v1/auth/login', ['mobile_number' => '+9779812345678', 'password' => 'Example@123'])
        ->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('returns ACCOUNT_EXISTS for an existing or deleted account', function (bool $deleted): void {
    $user = User::factory()->create(['mobile' => '+9779812345678']);
    if ($deleted) {
        $user->delete();
    }

    $this->postJson('/api/v1/auth/register', ['mobile_number' => $user->mobile])
        ->assertConflict()->assertJsonPath('code', 'ACCOUNT_EXISTS');
    $this->assertDatabaseCount('verification_codes', 0);
    Http::assertNothingSent();
})->with([false, true]);

it('rejects invalid mobile numbers and delivery methods', function (array $payload, string $field): void {
    $this->postJson('/api/v1/auth/register', $payload)
        ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('verification_codes', 0);
    Http::assertNothingSent();
})->with([
    'invalid mobile' => [['mobile_number' => '9812345678'], 'mobile_number'],
    'invalid delivery' => [['mobile_number' => '+9779812345678', 'delivery_method' => 'email'], 'delivery_method'],
]);

it('limits resends and invalidates the previous verification', function (): void {
    $id = pendingRegistration();
    $this->postJson('/api/v1/auth/verification/resend', ['verification_id' => $id])
        ->assertTooManyRequests()->assertJsonPath('code', 'RATE_LIMITED');
    $this->postJson('/api/v1/auth/register', ['mobile_number' => '+9779812345678'])
        ->assertTooManyRequests()->assertJsonPath('code', 'RATE_LIMITED');
    $this->travel(61)->seconds();
    $newId = $this->postJson('/api/v1/auth/verification/resend', ['verification_id' => $id])
        ->assertOk()->json('data.verification_id');
    $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => last_test_sms_code()])
        ->assertNotFound()->assertJsonPath('code', 'VERIFICATION_NOT_FOUND');
    $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $newId, 'code' => last_test_sms_code()])
        ->assertOk();
});

it('returns RATE_LIMITED when the registration route is throttled', function (): void {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/register', [])->assertTooManyRequests()->assertJsonPath('code', 'RATE_LIMITED');
});

it('rejects a missing or wrong-purpose verification', function (string $kind): void {
    $id = 'missing';
    if ($kind === 'password reset') {
        $user = User::factory()->create();
        $id = VerificationCode::query()->create([
            'user_id' => $user->id,
            'type' => VerificationType::PasswordReset,
            'code' => '1234',
            'expires_at' => now()->addMinutes(10),
        ])->id;
    }

    $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => '1234'])
        ->assertNotFound()->assertJsonPath('code', 'VERIFICATION_NOT_FOUND');
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['missing', 'password reset']);

it('rejects expired OTPs at the expiry boundary', function (): void {
    $this->freezeTime();
    $id = pendingRegistration();
    $this->travel(600)->seconds();
    $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => last_test_sms_code()])
        ->assertUnauthorized()->assertJsonPath('code', 'CODE_EXPIRED');
    $this->assertDatabaseCount('users', 0);
});

it('persists failed OTP attempts and refuses the correct code after five failures', function (): void {
    $id = pendingRegistration();
    $code = last_test_sms_code();
    $wrongCode = $code === '9999' ? '9998' : '9999';
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => $wrongCode])
            ->assertUnauthorized()->assertJsonPath('code', 'INVALID_CODE');
    }
    $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => $code])
        ->assertUnauthorized()->assertJsonPath('code', 'INVALID_CODE');
    $this->assertDatabaseHas('verification_codes', ['id' => $id, 'attempts' => 5, 'registration_token_hash' => null]);
});

it('consumes the exact OTP and never accepts a registration token as an application token', function (): void {
    $id = pendingRegistration();
    $code = last_test_sms_code();
    $verify = $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => $code])
        ->assertOk()->assertJsonPath('data.expires_in', 600);
    $token = $verify->json('data.registration_token');

    $this->assertDatabaseHas('verification_codes', ['id' => $id, 'registration_token_hash' => hash('sha256', $token)]);
    $this->assertDatabaseCount('users', 0);
    $this->withToken($token)->getJson('/api/v1/users/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $token])->assertUnauthorized();
    $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => $code])
        ->assertNotFound()->assertJsonPath('code', 'VERIFICATION_NOT_FOUND');
});

it('rejects invalid expired or reused registration tokens', function (string $kind): void {
    $this->freezeTime();
    $token = verifiedRegistration();
    if ($kind === 'invalid') {
        $token = 'invalid';
    } elseif ($kind === 'expired') {
        $this->travel(600)->seconds();
    } else {
        $this->postJson('/api/v1/auth/register/complete', completionPayload($token))->assertCreated();
    }

    $this->postJson('/api/v1/auth/register/complete', completionPayload($token))
        ->assertUnauthorized()->assertJsonPath('code', 'INVALID_REGISTRATION_TOKEN');
    $this->assertDatabaseCount('users', $kind === 'reused' ? 1 : 0);
})->with(['invalid', 'expired', 'reused']);

it('validates the password at completion and allows correction using the same token', function (array $invalid): void {
    $token = verifiedRegistration();
    $this->postJson('/api/v1/auth/register/complete', [...completionPayload($token), ...$invalid])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->assertDatabaseCount('users', 0);
    $this->postJson('/api/v1/auth/register/complete', completionPayload($token))->assertCreated();
})->with([
    'weak' => [['password' => 'weak', 'password_confirmation' => 'weak']],
    'mismatch' => [['password_confirmation' => 'Different@123']],
]);

it('creates the verified customer with a hashed password and supports authenticated login', function (): void {
    $token = verifiedRegistration();
    $complete = $this->postJson('/api/v1/auth/register/complete', [
        ...completionPayload($token), 'mobile_number' => '+9779800000000', 'role' => 'superadmin',
    ])
        ->assertCreated()->assertJsonPath('data.user.mobile_number', '+9779812345678')
        ->assertJsonPath('message', 'Account created successfully.')
        ->assertJsonPath('data.referral_applied', false)->assertJsonPath('data.expires_in', 3600);
    $user = User::query()->findOrFail($complete->json('data.user.id'));
    expect(Hash::check('Example@123', $user->password))->toBeTrue();
    expect($user->hasRole('customer'))->toBeTrue();
    expect($user->hasRole('superadmin'))->toBeFalse();
    $this->withToken($complete->json('data.access_token'))->getJson('/api/v1/users/me')->assertOk();
    $this->postJson('/api/v1/auth/login', ['mobile_number' => $user->mobile, 'password' => 'Example@123'])
        ->assertOk()->assertJsonPath('data.user.mobile_verified', true);
});

it('normalizes and attaches a valid referral only during completion', function (): void {
    $referrer = User::factory()->create();
    $referral = ReferralCode::query()->create(['user_id' => $referrer->id, 'code' => 'SPA-FRIEND-123']);
    $token = verifiedRegistration();
    $this->assertDatabaseCount('referrals', 0);

    $complete = $this->postJson('/api/v1/auth/register/complete', [
        ...completionPayload($token), 'referral_code' => '  spa-friend-123  ',
    ])->assertCreated()->assertJsonPath('data.referral_applied', true);
    $this->assertDatabaseHas('referrals', [
        'referral_code_id' => $referral->id,
        'referrer_user_id' => $referrer->id,
        'referred_user_id' => $complete->json('data.user.id'),
        'status' => 'pending',
    ]);
});

it('does not create accounts or sessions with an invalid referral', function (string $kind): void {
    $referrer = User::factory()->create();
    if ($kind !== 'unknown') {
        ReferralCode::query()->create([
            'user_id' => $referrer->id, 'code' => 'BAD-REFERRAL',
            'is_active' => $kind !== 'inactive',
            'expires_at' => $kind === 'expired' ? now()->subSecond() : null,
        ]);
    }
    $token = verifiedRegistration();
    $this->postJson('/api/v1/auth/register/complete', [...completionPayload($token), 'referral_code' => 'BAD-REFERRAL'])
        ->assertUnprocessable()->assertJsonValidationErrors('referral_code');
    $this->assertDatabaseMissing('users', ['mobile' => '+9779812345678']);
    $this->assertDatabaseCount('referrals', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('refresh_tokens', 0);
    $this->postJson('/api/v1/auth/register/complete', completionPayload($token))->assertCreated();
})->with(['unknown', 'inactive', 'expired']);

it('rejects a self owned referral when an account is created before completion', function (): void {
    $token = verifiedRegistration();
    $user = User::factory()->create(['mobile' => '+9779812345678']);
    ReferralCode::query()->create(['user_id' => $user->id, 'code' => 'SELF']);

    $this->postJson('/api/v1/auth/register/complete', [...completionPayload($token), 'referral_code' => 'SELF'])
        ->assertConflict()->assertJsonPath('code', 'ACCOUNT_EXISTS');
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('referrals', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('rejects unverified and disabled accounts at login', function (bool $disabled, string $error): void {
    $user = User::factory()->create([
        'password' => 'Example@123',
        'mobile_verified_at' => $disabled ? now() : null,
        'is_active' => ! $disabled,
    ]);
    $this->postJson('/api/v1/auth/login', ['mobile_number' => $user->mobile, 'password' => 'Example@123'])
        ->assertForbidden()->assertJsonPath('code', $error);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('refresh_tokens', 0);
})->with([[false, 'MOBILE_NOT_VERIFIED'], [true, 'ACCOUNT_DISABLED']]);

it('invalidates a previously issued registration token when registration restarts', function (): void {
    $token = verifiedRegistration();
    $this->travel(61)->seconds();
    pendingRegistration();

    $this->postJson('/api/v1/auth/register/complete', completionPayload($token))
        ->assertUnauthorized()->assertJsonPath('code', 'INVALID_REGISTRATION_TOKEN');
    $this->assertDatabaseCount('users', 0);
});

it('validates required completion fields before creating any account', function (): void {
    $this->postJson('/api/v1/auth/register/complete', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['registration_token', 'password']);
    $this->assertDatabaseCount('users', 0);
});

it('expires authenticated access tokens after the advertised hour', function (): void {
    $this->freezeTime();
    $complete = $this->postJson('/api/v1/auth/register/complete', completionPayload(verifiedRegistration()))
        ->assertCreated()->assertJsonPath('data.expires_in', 3600);

    $this->travel(3600)->seconds();
    $this->withToken($complete->json('data.access_token'))->getJson('/api/v1/users/me')->assertUnauthorized();
});
