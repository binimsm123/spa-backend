<?php

use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

beforeEach(function (): void {
    $GLOBALS['test_verification_codes'] = [];
    Http::fake([
        'https://api.sparrowsms.com/*' => function ($request) {
            if (preg_match('/\b(\d{4})\b/', (string) $request['text'], $matches)) {
                $GLOBALS['test_verification_codes'][] = $matches[1];
            }

            return Response::fake(['count' => 1, 'response_code' => 200, 'response' => 'queued']);
        },
    ]);
});

// -------------------------------------------------------------------------
// Shared helpers
// -------------------------------------------------------------------------

if (! function_exists('registerCustomer')) {
    /**
     * Register, verify, and return [user, tokens] for a fresh customer.
     */
    function registerCustomer(array $overrides = []): array
    {
        $payload = [
            'mobile_number' => '+97798'.random_int(1000000, 9999999),
            'password' => 'Customer#1',
            'password_confirmation' => 'Customer#1',
            'display_name' => 'Test Customer',
            ...$overrides,
        ];

        $response = test()->postJson('/api/v1/auth/register', $payload);
        $verificationId = $response->json('data.verification_id');
        $userId = VerificationCode::query()->findOrFail($verificationId)->user_id;
        $user = User::query()->findOrFail($userId);
        $code = last_test_sms_code();

        $verify = test()->postJson('/api/v1/auth/register/verify', ['verification_id' => $verificationId, 'code' => $code]);

        return [
            $user->fresh(),
            $verify->json('data'),
        ];
    }
}

if (! function_exists('last_test_sms_code')) {
    /**
     * Read the last issued verification code from the log (test-only channel).
     */
    function last_test_sms_code(): string
    {
        $codes = $GLOBALS['test_verification_codes'] ?? [];

        return (string) (array_pop($codes) ?: config('spa.auth.static_verification_code', '1234'));
    }
}

if (! function_exists('actingAsCustomer')) {
    function actingAsCustomer(array $overrides = []): array
    {
        [$user, $tokens] = registerCustomer($overrides);

        test()->actingAs($user, 'sanctum');

        return [$user, $tokens];
    }
}

if (! function_exists('actingAsSuperadmin')) {
    function actingAsSuperadmin(): User
    {
        $admin = User::query()->create([
            'mobile' => '+97798'.random_int(1000000, 9999999),
            'display_name' => 'Admin',
            'password' => 'AdminPass#1',
            'is_active' => true,
            'mobile_verified_at' => now(),
        ]);

        $admin->assignRole('superadmin');
        test()->actingAs($admin, 'sanctum');

        return $admin;
    }
}

if (! function_exists('createBusinessWithService')) {
    /**
     * Create an active online business with hours, service, and current price.
     */
    function createBusinessWithService(array $overrides = []): array
    {
        $business = Business::query()->create([
            'name' => $overrides['name'] ?? 'Test Spa',
            'slug' => $overrides['slug'] ?? 'test-spa-'.Str::lower(Illuminate\Support\Str::random(6)),
            'is_verified' => true,
            'is_online' => true,
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        foreach (range(0, 6) as $weekday) {
            BusinessHour::query()->create([
                'business_id' => $business->getKey(),
                'weekday' => $weekday,
                'opens_at' => '09:00',
                'closes_at' => '17:00',
                'is_closed' => false,
            ]);
        }

        $service = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => $overrides['service_name'] ?? 'Test Massage',
            'slug' => 'test-massage-'.Str::lower(Illuminate\Support\Str::random(6)),
            'max_people' => 2,
            'is_bookable' => true,
            'status' => 'active',
        ]);

        $price = ServicePrice::query()->create([
            'service_id' => $service->getKey(),
            'duration' => '01:00',
            'price_minor' => 500000,
            'currency' => 'NPR',
            'is_current' => true,
        ]);

        return [$business, $service, $price];
    }
}
