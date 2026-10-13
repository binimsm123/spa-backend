<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Dto\Api\AuthSessionData;
use App\Http\Dto\Api\TokenData;
use App\Http\Requests\Auth\CompleteRegistrationRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\LogoutRequest;
use App\Http\Requests\Auth\PasswordChangeRequest;
use App\Http\Requests\Auth\PasswordForgotRequest;
use App\Http\Requests\Auth\PasswordResendRequest;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Http\Requests\Auth\PasswordVerifyRequest;
use App\Http\Requests\Auth\RefreshRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyRegistrationRequest;
use App\Models\RefreshToken;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuthService $auth,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $verification = $this->auth->register($data);

        return $this->success(
            [
                'verification_id' => $verification->getKey(),
                'delivery_method' => $data['delivery_method'] ?? 'sms',
                'masked_destination' => $this->maskMobile($verification->mobile_number),
                'expires_in' => max(0, (int) ceil(now()->diffInSeconds($verification->expires_at, false))),
            ],
            'Verification code sent.',
            Response::HTTP_CREATED,
        );
    }

    public function verifyRegistration(VerifyRegistrationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $registration = $this->auth->verifyRegistration($data['verification_id'], $data['code']);

        return $this->success($registration, 'Mobile number verified. Create a password to finish registration.');
    }

    public function completeRegistration(CompleteRegistrationRequest $request): JsonResponse
    {
        $tokens = $this->auth->completeRegistration($request->validated());

        return $this->success([
            ...$this->tokenPayload($tokens, true),
            'referral_applied' => $tokens['referral_applied'],
        ], 'Account created successfully.', Response::HTTP_CREATED);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $data = $request->validate(['verification_id' => ['required', 'string']]);
        $verification = $this->auth->resendVerification($data['verification_id']);

        return $this->success([
            'verification_id' => $verification->getKey(),
            'expires_in' => max(0, (int) now()->diffInSeconds($verification->expires_at, false)),
            'resend_available_in' => 60,
        ], 'A new verification code was sent.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $tokens = $this->auth->login(
            $request->validated('mobile_number'),
            $request->validated('password'),
        );

        return $this->success($this->tokenPayload($tokens, true), 'Logged in successfully.');
    }

    public function refresh(RefreshRequest $request): JsonResponse
    {
        $tokens = $this->auth->rotateRefreshToken($request->validated('refresh_token'));

        return $this->success($this->tokenPayload($tokens), 'Session refreshed.');
    }

    public function passwordForgot(PasswordForgotRequest $request): JsonResponse
    {
        $verification = $this->auth->sendPasswordResetCode($request->validated('mobile_number'));

        // Same response whether or not the account exists.
        return $this->success([
            'reset_id' => $verification?->getKey(),
            'masked_destination' => $verification ? $this->maskMobile($verification->user->mobile) : null,
            'expires_in' => $verification ? max(0, now()->diffInSeconds($verification->expires_at, false)) : 600,
        ], 'Password reset code sent.');
    }

    public function passwordResend(PasswordResendRequest $request): JsonResponse
    {
        $oldVerification = VerificationCode::query()->with('user')->findOrFail($request->validated('reset_id'));
        $verification = $this->auth->sendPasswordResetCode($oldVerification->user->mobile);

        return $this->success([
            'reset_id' => $verification?->getKey(),
            'masked_destination' => $verification ? $this->maskMobile($verification->user->mobile) : null,
            'expires_in' => $verification ? max(0, now()->diffInSeconds($verification->expires_at, false)) : 600,
            'resend_available_in' => 60,
        ], 'A new password reset code was sent.');
    }

    public function passwordVerify(PasswordVerifyRequest $request): JsonResponse
    {
        $verification = VerificationCode::query()->with('user')->findOrFail($request->validated('reset_id'));

        $resetToken = $this->auth->verifyPasswordResetCode($verification, $request->validated('code'));

        return $this->success(['reset_token' => $resetToken, 'expires_in' => 600], 'Code verified.');
    }

    public function passwordReset(PasswordResetRequest $request): JsonResponse
    {
        $data = $request->validated();

        // The reset token is a short-lived refresh session owned by the user.
        $session = RefreshToken::query()
            ->where('token_hash', hash('sha256', $data['reset_token']))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $user = $session->user;

        $this->auth->resetPassword($user, $data['password']);
        $this->auth->revokeAllSessions($user);

        return $this->success(null, 'Password reset successfully.');
    }

    public function passwordChange(PasswordChangeRequest $request): JsonResponse
    {
        $this->auth->changePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
        );

        return $this->success(['changed_at' => now()->toIso8601String(), 'other_sessions_revoked' => true], 'Password changed successfully.');
    }

    public function logout(LogoutRequest $request): JsonResponse
    {
        $this->auth->logoutByRefreshToken($request->validated('refresh_token'));

        $request->user()?->currentAccessToken()?->delete();

        return $this->success(null, 'Logged out successfully.');
    }

    /**
     * @param  array{user: User, access_token: string, refresh_token: string}  $tokens
     * @return array<string, mixed>
     */
    private function tokenPayload(array $tokens, bool $includeUser = false): array
    {
        if ($includeUser) {
            return (new AuthSessionData(
                $tokens['user'],
                $tokens['access_token'],
                $tokens['refresh_token'],
            ))->toArray();
        }

        return (new TokenData(
            $tokens['access_token'],
            $tokens['refresh_token'],
        ))->toArray();
    }

    private function maskMobile(string $mobile): string
    {
        if (str_starts_with($mobile, '+977') && strlen($mobile) === 14) {
            return substr($mobile, 0, 4).' '.substr($mobile, 4, 2).'******'.substr($mobile, -2);
        }

        return substr($mobile, 0, 3).str_repeat('*', max(0, strlen($mobile) - 5)).substr($mobile, -2);
    }
}
