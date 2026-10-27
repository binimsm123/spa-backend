<?php

namespace App\Services;

use App\Enums\VerificationType;
use App\Jobs\SendVerificationCodeJob;
use App\Models\ReferralCode;
use App\Models\RefreshToken;
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

    public function register(array $data): VerificationCode
    {
        return DB::transaction(function () use ($data): VerificationCode {
            $mobile = $data['mobile_number'];
            $this->ensureAccountDoesNotExist($mobile);

            $latest = VerificationCode::query()
                ->where('mobile_number', $mobile)
                ->where('type', VerificationType::Registration->value)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->lockForUpdate()->first();

            if ($latest?->last_sent_at?->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
                throw new ApiException(ErrorCode::RateLimited, 'Please wait before requesting another code.');
            }

            VerificationCode::query()
                ->where('mobile_number', $mobile)
                ->where('type', VerificationType::Registration->value)
                ->update(['consumed_at' => now(), 'registration_token_hash' => null]);

            $code = app()->environment(['local', 'testing'])
                ? (string) config('spa.auth.static_verification_code', '1234')
                : (string) random_int(1000, 9999);

            $verification = VerificationCode::query()->create([
                'mobile_number' => $mobile,
                'type' => VerificationType::Registration,
                'code' => $code,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'last_sent_at' => now(),
            ]);

            SendVerificationCodeJob::dispatch($mobile, $this->verificationMessage($code, VerificationType::Registration))->afterCommit();

            return $verification;
        }, 3);
    }

    /** @return array{registration_token: string, mobile_number: string, mobile_verified: bool, expires_in: int} */
    public function verifyRegistration(string $verificationId, string $code): array
    {
        $result = DB::transaction(function () use ($verificationId, $code): array|ApiException {
            $verification = VerificationCode::query()
                ->whereKey($verificationId)
                ->where('type', VerificationType::Registration->value)
                ->whereNull('user_id')->whereNotNull('mobile_number')
                ->whereNull('consumed_at')->lockForUpdate()->first();

            if ($verification === null) {
                throw new ApiException(ErrorCode::VerificationNotFound, 'No active registration verification was found.');
            }
            if ($verification->expires_at->lte(now())) {
                throw new ApiException(ErrorCode::CodeExpired, 'This code has expired. Request a new one.');
            }
            if ($verification->attempts >= 5 || ! hash_equals($verification->code, $code)) {
                if ($verification->attempts < 5) {
                    $verification->increment('attempts');
                }

                return new ApiException(ErrorCode::InvalidCode, 'The verification code is incorrect or no longer usable.');
            }

            $token = Str::random(60);
            $verification->forceFill([
                'consumed_at' => now(),
                'registration_token_hash' => hash('sha256', $token),
                'registration_token_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            ])->save();

            return [
                'registration_token' => $token,
                'mobile_number' => $verification->mobile_number,
                'mobile_verified' => true,
                'expires_in' => self::CODE_TTL_MINUTES * 60,
            ];
        });

        if ($result instanceof ApiException) {
            throw $result;
        }

        return $result;
    }

    /** @return array{user: User, access_token: string, refresh_token: string, expires_in: int, referral_applied: bool} */
    public function completeRegistration(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $verification = VerificationCode::query()
                ->where('registration_token_hash', hash('sha256', $data['registration_token']))
                ->where('type', VerificationType::Registration->value)
                ->whereNull('user_id')->whereNotNull('mobile_number')
                ->whereNotNull('consumed_at')
                ->where('registration_token_expires_at', '>', now())
                ->lockForUpdate()->first();

            if ($verification === null) {
                throw new ApiException(ErrorCode::InvalidRegistrationToken, 'Registration token is invalid or expired.');
            }

            $mobile = $verification->mobile_number;
            $this->ensureAccountDoesNotExist($mobile);
            $referral = filled($data['referral_code'] ?? null)
                ? $this->validateReferralCode($data['referral_code'], $mobile)
                : null;

            $user = User::create([
                'mobile' => $mobile,
                'password' => $data['password'],
                'display_name' => $mobile,
                'timezone' => 'Asia/Kathmandu',
                'mobile_verified_at' => now(),
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

            $verification->forceFill(['user_id' => $user->getKey(), 'registration_token_hash' => null])->save();

            return [...$this->issueTokens($user, 'registration-completed'), 'referral_applied' => $referral !== null];
        });
    }

    public function resendVerification(string $verificationId): VerificationCode
    {
        $verification = VerificationCode::query()
            ->whereKey($verificationId)
            ->where('type', VerificationType::Registration->value)
            ->whereNull('user_id')->whereNotNull('mobile_number')
            ->whereNull('consumed_at')->first();

        if ($verification === null) {
            throw new ApiException(ErrorCode::VerificationNotFound, 'No active registration verification was found.');
        }

        return $this->register(['mobile_number' => $verification->mobile_number]);
    }

    private function ensureAccountDoesNotExist(string $mobile): void
    {
        if (User::withTrashed()->where('mobile', $mobile)->exists()) {
            throw new ApiException(ErrorCode::AccountExists, 'An account with this mobile number already exists.');
        }
    }

    // -------------------------------------------------------------------------
    // Login / refresh / logout
    // -------------------------------------------------------------------------

    public function login(string $identifier, string $password): array
    {
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false ? 'email' : 'mobile';

        /** @var User|null $user */
        $user = User::where($field, $identifier)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new ApiException(ErrorCode::InvalidCredentials, $field === 'email' ? 'Invalid email or password.' : 'Invalid mobile number or password.');
        }

        if (! $user->is_active) {
            throw new ApiException(ErrorCode::AccountDisabled, 'This account has been deactivated.');
        }

        if ($user->mobile_verified_at === null) {
            throw new ApiException(ErrorCode::MobileNotVerified, 'Verify your mobile number before logging in.');
        }

        return $this->issueTokens($user, 'login');
    }

    /**
     * @return array{user: User, access_token: string, refresh_token: string, expires_in: int}
     */
    public function issueTokens(User $user, string $deviceName): array
    {
        $accessTtlMinutes = max(1, (int) config('spa.auth.access_ttl_minutes', 10080));
        $accessToken = $user->createToken($deviceName, ['*'], now()->addMinutes($accessTtlMinutes))->plainTextToken;

        $refreshToken = $this->createRefreshToken($user);

        return ['user' => $user, 'access_token' => $accessToken, 'refresh_token' => $refreshToken, 'expires_in' => $accessTtlMinutes * 60];
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

            if ($refreshToken === null || $refreshToken->user === null) {
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
        $result = DB::transaction(function () use ($verification, $code): string|ApiException {
            $current = VerificationCode::query()->whereKey($verification->getKey())
                ->where('type', VerificationType::PasswordReset->value)
                ->whereNull('consumed_at')->lockForUpdate()->first();

            if ($current === null || $current->user === null || ! $current->user->is_active) {
                return new ApiException(ErrorCode::InvalidVerificationCode, 'No active verification code. Request a new one.');
            }
            if ($current->expires_at->lte(now())) {
                return new ApiException(ErrorCode::VerificationCodeExpired, 'This code has expired. Request a new one.');
            }
            if ($current->attempts >= 5 || ! hash_equals($current->code, $code)) {
                if ($current->attempts < 5) {
                    $current->increment('attempts');
                }

                return new ApiException(ErrorCode::InvalidVerificationCode, 'The verification code is incorrect or no longer usable.');
            }

            $token = Str::random(60);
            $current->forceFill([
                'consumed_at' => now(),
                'reset_token_hash' => hash('sha256', $token),
                'reset_token_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            ])->save();

            return $token;
        });

        if ($result instanceof ApiException) {
            throw $result;
        }

        return $result;
    }

    public function resetPasswordWithToken(string $token, string $password): void
    {
        DB::transaction(function () use ($token, $password): void {
            $verification = VerificationCode::query()->with('user')
                ->where('type', VerificationType::PasswordReset->value)
                ->where('reset_token_hash', hash('sha256', $token))
                ->whereNotNull('consumed_at')
                ->where('reset_token_expires_at', '>', now())
                ->lockForUpdate()->first();

            if ($verification === null || $verification->user === null || ! $verification->user->is_active) {
                throw new ApiException(ErrorCode::InvalidResetToken, 'Password reset token is invalid or expired.');
            }

            $this->resetPassword($verification->user, $password);
            $this->revokeAllSessions($verification->user);
            VerificationCode::query()->where('user_id', $verification->user_id)
                ->where('type', VerificationType::PasswordReset->value)
                ->update(['consumed_at' => now(), 'reset_token_hash' => null, 'reset_token_expires_at' => null]);
        });
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

        $this->revokeAllSessions($user);
    }

    // -------------------------------------------------------------------------
    // Verification codes
    // -------------------------------------------------------------------------

    public function issueVerificationCode(User $user, VerificationType $type): VerificationCode
    {
        return DB::transaction(function () use ($user, $type): VerificationCode {
            $latest = VerificationCode::query()->where('user_id', $user->getKey())
                ->where('type', $type->value)->orderByDesc('created_at')->orderByDesc('id')
                ->lockForUpdate()->first();

            if ($latest?->last_sent_at?->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
                throw new ApiException(ErrorCode::Throttled, 'Please wait before requesting another code.');
            }

            VerificationCode::query()->where('user_id', $user->getKey())
                ->where('type', $type->value)
                ->update(['consumed_at' => now(), 'reset_token_hash' => null, 'reset_token_expires_at' => null]);

            $code = app()->environment(['local', 'testing'])
                ? (string) config('spa.auth.static_verification_code', '1234')
                : (string) random_int(1000, 9999);

            $verification = VerificationCode::query()->create([
                'user_id' => $user->getKey(),
                'type' => $type->value,
                'code' => $code,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'last_sent_at' => now(),
            ]);

            SendVerificationCodeJob::dispatch($user->mobile, $this->verificationMessage($code, $type))->afterCommit();

            return $verification;
        }, 3);
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

    private function validateReferralCode(string $rawCode, string $mobile): ReferralCode
    {
        $code = strtoupper(trim($rawCode));

        /** @var ReferralCode|null $referralCode */
        $referralCode = ReferralCode::query()->with('user')->where('code', $code)->where('is_active', true)->lockForUpdate()->first();

        if ($referralCode === null || $referralCode->user === null || $referralCode->user->mobile === $mobile
            || ($referralCode->expires_at !== null && $referralCode->expires_at->lte(now()))) {
            throw ApiException::validation('The referral code is not valid.', ['referral_code' => ['The referral code is not valid.']]);
        }

        return $referralCode;
    }
}
