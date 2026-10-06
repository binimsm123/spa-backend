<?php

namespace App\Services;

// use App\Jobs\SendVerificationCodeJob;
use App\Enums\VerificationType;
use App\Models\RefreshToken;
use App\Models\ReferralCode;
use App\Models\User;
use App\Models\VerificationCode;
use App\Support\ApiException;
use App\Support\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    /** Registration / reset codes expire after 10 minutes. */
    public const CODE_TTL_MINUTES = 10;

    /** Codes may be resent once per 60 seconds. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SparrowSmsService $sms,
    ) {}

    // -------------------------------------------------------------------------
    // Registration + verification
    // -------------------------------------------------------------------------

    /**
     * @return array{user: User, verification: VerificationCode}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $referral = null;
            if (! empty($data['referral_code'])) {
                $referral = $this->validateReferralCode($data['referral_code']);
            }

            /** @var User $user */
            $user = User::create([
                'mobile' => $data['mobile_number'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'display_name' => $data['display_name'] ?? $data['mobile_number'],
                'timezone' => $data['timezone'] ?? 'Asia/Kathmandu',
            ]);

            $user->assignRole('customer');

            if ($referral !== null) {
                DB::table('referrals')->insert([
                    'id' => (string) Str::ulid(),
                    'referral_code_id' => $referral->getKey(),
                    'referrer_user_id' => $referral->user_id,
                    'referred_user_id' => $user->getKey(),
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $verification = $this->issueVerificationCode($user, VerificationType::Registration);

            return [
                'user' => $user,
                'verification' => $verification,
            ];
        });
    }

    public function verifyRegistration(User $user, string $code): array
    {
        $this->consumeCode($user, VerificationType::Registration, $code);

        $user->forceFill(['mobile_verified_at' => now()])->save();

        return $this->issueTokens($user, 'registration-verified');
    }

    public function resendVerification(User $user): VerificationCode
    {
        return $this->issueVerificationCode($user, VerificationType::Registration);
    }

    // -------------------------------------------------------------------------
    // Login / refresh / logout
    // -------------------------------------------------------------------------

    public function login(string $mobileNumber, string $password): array
    {
        /** @var User|null $user */
        $user = User::where('mobile', $mobileNumber)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new ApiException(ErrorCode::InvalidCredentials, 'Invalid mobile number or password.');
        }

        if (! $user->is_active) {
            throw new ApiException(ErrorCode::AccountDisabled, 'This account has been deactivated.');
        }

        return $this->issueTokens($user, 'login');
    }

    /**
     * @return array{user: User, access_token: string, refresh_token: string}
     */
    public function issueTokens(User $user, string $deviceName): array
    {
        $accessToken = $user->createToken($deviceName)->plainTextToken;

        $refreshToken = $this->createRefreshToken($user);

        return ['user' => $user, 'access_token' => $accessToken, 'refresh_token' => $refreshToken];
    }

    public function createRefreshToken(User $user): string
    {
        $plain = Str::random(60);

        $user->refreshTokens()->create([
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays((int) config('spa.auth.refresh_ttl_days', 30)),
            'user_agent' => request()?->userAgent(),
            'ip_address' => request()?->ip(),
        ]);

        return $plain;
    }

    /**
     * Rotate a refresh token: revoke the old row, mint a new session.
     */
    public function rotateRefreshToken(string $plainToken): array
    {
        $user = DB::transaction(function () use ($plainToken): User {
            $refreshToken = RefreshToken::query()
                ->with('user')
                ->where('token_hash', hash('sha256', $plainToken))
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($refreshToken === null) {
                throw new ApiException(ErrorCode::Unauthenticated, 'Refresh token is invalid or expired.');
            }

            $user = $refreshToken->user;

            if (! $user->is_active) {
                throw new ApiException(ErrorCode::AccountDisabled, 'This account has been deactivated.');
            }

            $refreshToken->forceFill([
                'rotated_at' => now(),
                'revoked_at' => now(),
            ])->save();

            return $user;
        });

        return $this->issueTokens($user, 'refresh');
    }

    public function logoutByRefreshToken(string $plainToken): void
    {
        RefreshToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeAllSessions(User $user, ?int $exceptTokenId = null): void
    {
        RefreshToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->when($exceptTokenId !== null, fn ($q) => $q->where('id', '!=', $exceptTokenId))
            ->update(['revoked_at' => now()]);

        $user->tokens()->when($exceptTokenId !== null, fn ($q) => $q->where('id', '!=', $exceptTokenId))->delete();
    }

    public function revokeOtherSessions(User $user, string $currentRefreshToken): void
    {
        $currentHash = hash('sha256', $currentRefreshToken);

        RefreshToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->where('token_hash', '!=', $currentHash)
            ->update(['revoked_at' => now()]);

        $user->tokens()->delete();
    }

    // -------------------------------------------------------------------------
    // Password reset
    // -------------------------------------------------------------------------

    public function sendPasswordResetCode(string $mobileNumber): ?VerificationCode
    {
        $user = User::where('mobile', $mobileNumber)->first();

        // Never reveal whether the account exists.
        if ($user === null) {
            return null;
        }

        return $this->issueVerificationCode($user, VerificationType::PasswordReset);
    }

    /**
     * Verify the reset code and mint a short-lived reset token.
     */
    public function verifyPasswordResetCode(VerificationCode $verification, string $code): string
    {
        $this->consumeCode($verification->user, VerificationType::PasswordReset, $code, $verification->getKey());

        return $this->createRefreshToken($verification->user);
    }

    public function resetPassword(User $user, string $newPassword): void
    {
        $user->forceFill(['password' => $newPassword])->save();
    }

    public function changePassword(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw new ApiException(ErrorCode::InvalidCredentials, 'The current password is incorrect.');
        }

        $user->forceFill(['password' => $new])->save();

        // Revoke other refresh sessions; the current session survives.
        $currentRefresh = request()?->bearerToken();

        RefreshToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->when($currentRefresh !== null, fn ($q) => $q->where('token_hash', '!=', hash('sha256', $currentRefresh)))
            ->update(['revoked_at' => now()]);
    }

    // -------------------------------------------------------------------------
    // Verification codes
    // -------------------------------------------------------------------------

    public function issueVerificationCode(User $user, VerificationType $type): VerificationCode
    {
        // Enforce the resend cooldown.
        $latest = VerificationCode::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type->value)
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->first();

        if ($latest !== null && $latest->last_sent_at !== null && $latest->last_sent_at->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            throw new ApiException(ErrorCode::Throttled, 'Please wait before requesting another code.');
        }

        // Temporary test-only OTP. Replace with random generation before production.
        $code = (string) config('spa.auth.static_verification_code', '1234');
        //$code = (string) random_int(1000, 9999);
        return VerificationCode::query()->create([
            'user_id' => $user->getKey(),
            'type' => $type->value,
            'code' => $code,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'last_sent_at' => now(),
        ]);

        /*
         * Enable when real OTP delivery is ready. This dispatches after the
         * transaction commits so SMS delivery runs in the queue worker and
         * does not block the registration request.
         *
         * SendVerificationCodeJob::dispatch(
         *     $user->mobile,
         *     $this->verificationMessage($code, $type),
         * )->afterCommit();
         */
    }

    private function verificationMessage(string $code, VerificationType $type): string
    {
        return match ($type) {
            VerificationType::PasswordReset => "Your SPA password reset code is {$code}. It expires in 10 minutes.",
            default => "Your SPA verification code is {$code}. It expires in 10 minutes.",
        };
    }

    public function consumeCode(User $user, VerificationType $type, string $code, ?string $verificationId = null): void
    {
        /** @var VerificationCode|null $verification */
        $verification = VerificationCode::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type->value)
            ->whereNull('consumed_at')
            ->when($verificationId !== null, fn ($query) => $query->whereKey($verificationId))
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first();

        if ($verification === null) {
            throw new ApiException(ErrorCode::InvalidVerificationCode, 'No active verification code. Request a new one.');
        }

        if ($verification->expires_at->isPast()) {
            throw new ApiException(ErrorCode::VerificationCodeExpired, 'This code has expired. Request a new one.');
        }

        if ($code !== $verification->code) {
            $verification->increment('attempts');
            throw new ApiException(ErrorCode::InvalidVerificationCode, 'The verification code is incorrect.');
        }

        $verification->forceFill(['consumed_at' => now()])->save();
    }

    // -------------------------------------------------------------------------
    // Referral code validation
    // -------------------------------------------------------------------------

    private function validateReferralCode(string $rawCode): ReferralCode
    {
        $code = strtoupper(trim($rawCode));

        /** @var ReferralCode|null $referralCode */
        $referralCode = ReferralCode::query()->where('code', $code)->where('is_active', true)->first();

        if ($referralCode === null) {
            throw new ApiException(ErrorCode::NotFound, 'The referral code is not valid.');
        }

        return $referralCode;
    }

}
