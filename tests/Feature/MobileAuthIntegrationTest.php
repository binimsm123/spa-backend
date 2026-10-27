<?php

use App\Models\VerificationCode;
use Illuminate\Support\Facades\Http;

function mobileResetToken(array $tokens): array
{
    $forgot = test()->postJson('/api/v1/auth/password/forgot', [
        'mobile_number' => $tokens['user']['mobile_number'],
        'delivery_method' => 'sms',
    ])->assertOk();

    $id = $forgot->json('data.reset_id');
    $verified = test()->postJson('/api/v1/auth/password/verify', [
        'reset_id' => $id,
        'code' => last_test_sms_code(),
    ])->assertOk();

    return [$id, $verified->json('data.reset_token')];
}

it('uses real bearer tokens for login profile refresh and logout on mobile', function (): void {
    [$user] = registerCustomer();
    $login = $this->postJson('/api/v1/auth/login', [
        'mobile_number' => $user->mobile,
        'password' => 'Customer#1',
    ])->assertOk()->json('data');

    $this->app['auth']->forgetGuards();
    $this->withToken($login['access_token'])->getJson('/api/v1/users/me')
        ->assertOk()->assertJsonPath('data.id', $user->id);

    $this->travel((int) config('spa.auth.access_ttl_minutes') + 1)->minutes();
    $this->app['auth']->forgetGuards();
    $this->withToken($login['access_token'])->getJson('/api/v1/users/me')->assertUnauthorized();
    $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])
        ->assertOk()->json('data');
    $this->app['auth']->forgetGuards();
    $this->withToken($rotated['access_token'])->getJson('/api/v1/users/me')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($rotated['access_token'])->postJson('/api/v1/auth/logout', ['refresh_token' => $rotated['refresh_token']])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($rotated['access_token'])->getJson('/api/v1/users/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $rotated['refresh_token']])->assertUnauthorized();
});

it('sends reset SMS and keeps reset tokens separate from login sessions', function (): void {
    [, $tokens] = registerCustomer();
    [$id, $reset] = mobileResetToken($tokens);
    Http::assertSent(fn ($request) => str_contains($request['text'], 'password reset code'));
    $this->assertDatabaseHas('verification_codes', ['id' => $id, 'reset_token_hash' => hash('sha256', $reset)]);
    $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => hash('sha256', $reset)]);

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $reset])->assertUnauthorized();
    $this->postJson('/api/v1/auth/password/reset', [
        'reset_token' => $tokens['refresh_token'],
        'password' => 'ResetPassword#9',
        'password_confirmation' => 'ResetPassword#9',
    ])->assertUnauthorized()->assertJsonPath('code', 'INVALID_RESET_TOKEN');

    $body = ['reset_token' => $reset, 'password' => 'ResetPassword#9', 'password_confirmation' => 'ResetPassword#9'];
    $this->postJson('/api/v1/auth/password/reset', $body)->assertOk();
    $this->postJson('/api/v1/auth/password/reset', $body)->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($tokens['access_token'])->getJson('/api/v1/users/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
});

it('expires reset tokens after ten minutes', function (): void {
    [, $tokens] = registerCustomer();
    [, $reset] = mobileResetToken($tokens);
    $this->travel(601)->seconds();
    $this->postJson('/api/v1/auth/password/reset', [
        'reset_token' => $reset, 'password' => 'ResetPassword#9', 'password_confirmation' => 'ResetPassword#9',
    ])->assertUnauthorized()->assertJsonPath('code', 'INVALID_RESET_TOKEN');
});

it('replaces reset challenges on resend and rejects old codes and reset tokens', function (): void {
    [, $tokens] = registerCustomer();
    [$id, $reset] = mobileResetToken($tokens);
    $this->travel(61)->seconds();
    $newId = $this->postJson('/api/v1/auth/password/resend', ['reset_id' => $id])->assertOk()->json('data.reset_id');
    expect($newId)->not->toBe($id);
    $this->postJson('/api/v1/auth/password/verify', ['reset_id' => $id, 'code' => last_test_sms_code()])->assertUnauthorized();
    $this->postJson('/api/v1/auth/password/reset', [
        'reset_token' => $reset, 'password' => 'ResetPassword#9', 'password_confirmation' => 'ResetPassword#9',
    ])->assertUnauthorized();
    $this->postJson('/api/v1/auth/password/verify', ['reset_id' => $newId, 'code' => last_test_sms_code()])->assertOk();
});

it('locks reset verification after five incorrect attempts', function (): void {
    [, $tokens] = registerCustomer();
    $id = $this->postJson('/api/v1/auth/password/forgot', ['mobile_number' => $tokens['user']['mobile_number']])
        ->assertOk()->json('data.reset_id');
    $correct = last_test_sms_code();
    $wrong = $correct === '0000' ? '9999' : '0000';
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/password/verify', ['reset_id' => $id, 'code' => $wrong])->assertUnauthorized();
    }
    $this->postJson('/api/v1/auth/password/verify', ['reset_id' => $id, 'code' => $correct])->assertUnauthorized();
    expect(VerificationCode::findOrFail($id)->attempts)->toBe(5);
});

it('requires sign in again after changing a password and revokes all devices', function (): void {
    [, $tokens] = registerCustomer();
    $other = $this->postJson('/api/v1/auth/login', ['mobile_number' => $tokens['user']['mobile_number'], 'password' => 'Customer#1'])
        ->assertOk()->json('data');
    $this->app['auth']->forgetGuards();
    $this->withToken($tokens['access_token'])->postJson('/api/v1/auth/password/change', [
        'current_password' => 'Customer#1', 'password' => 'ChangedPassword#9', 'password_confirmation' => 'ChangedPassword#9',
    ])->assertOk()->assertJsonPath('data.reauthentication_required', true);
    foreach ([$tokens, $other] as $session) {
        $this->app['auth']->forgetGuards();
        $this->withToken($session['access_token'])->getJson('/api/v1/users/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertUnauthorized();
    }
    $this->postJson('/api/v1/auth/login', ['mobile_number' => $tokens['user']['mobile_number'], 'password' => 'ChangedPassword#9'])->assertOk();
});
