<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\BusinessUser;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

pest()->beforeEach(function (): void {
    $GLOBALS['test_verification_codes'] = [];
    Http::preventStrayRequests();
    Http::fake([
        'https://api.sparrowsms.com/*' => function ($request) {
            if (preg_match('/\b(\d{4})\b/', (string) $request['text'], $matches)) {
                $GLOBALS['test_verification_codes'][] = $matches[1];
            }

            return Http::response(['count' => 1, 'response_code' => 200, 'response' => 'queued']);
        },
    ]);
})->in('Feature', 'Unit');

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
            'mobile_number' => '+97798'.random_int(10000000, 99999999),
            'password' => 'Customer#1',
            'password_confirmation' => 'Customer#1',
            'display_name' => 'Test Customer',
            ...$overrides,
        ];

        $response = test()->postJson('/api/v1/auth/register', [
            'mobile_number' => $payload['mobile_number'],
            'delivery_method' => 'sms',
        ])->assertCreated();
        $verificationId = $response->json('data.verification_id');
        $verify = test()->postJson('/api/v1/auth/register/verify', [
            'verification_id' => $verificationId,
            'code' => last_test_sms_code(),
        ])->assertOk();
        $complete = test()->postJson('/api/v1/auth/register/complete', [
            ...$payload,
            'registration_token' => $verify->json('data.registration_token'),
        ])->assertCreated();

        return [User::query()->findOrFail($complete->json('data.user.id')), $complete->json('data')];
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
            'mobile' => '+97798'.random_int(10000000, 99999999),
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

/** @return array{Business, Service, ServicePrice} */
function createBookableBusinessWithService(array $overrides = []): array
{
    [$business, $service, $price] = createBusinessWithService($overrides);
    $membership = BusinessUser::query()->create([
        'business_id' => $business->id, 'user_id' => User::factory()->create()->id, 'role' => 'staff', 'is_active' => true,
    ]);
    $membership->services()->attach($service);
    foreach (range(0, 6) as $weekday) {
        $membership->hours()->create(['weekday' => $weekday, 'opens_at' => '00:00', 'closes_at' => '23:59', 'is_closed' => false]);
    }

    return [$business, $service, $price];
}

/** @return array{Business, Service, BusinessUser, string} */
function automaticAvailabilityFixture(): array
{
    test()->travelTo(Carbon::parse('2026-11-13 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $membership = $service->staffMemberships()->firstOrFail();
    $membership->hours->each->update(['opens_at' => '10:00', 'closes_at' => '16:00', 'breaks' => [['name' => 'Break', 'starts_at' => '12:00', 'ends_at' => '13:00']]]);
    test()->actingAs(User::factory()->create(), 'sanctum');

    return [$business, $service, $membership, "/api/v1/businesses/{$business->id}/services/{$service->id}/availability?date=2026-11-14"];
}

function automaticBooking(Business $business, ?string $staffId, string $start = '10:00', string $end = '11:00', string $status = 'confirmed'): Booking
{
    return Booking::query()->create([
        'business_id' => $business->id, 'user_id' => User::factory()->create()->id, 'assigned_staff_user_id' => $staffId,
        'appointment_date' => '2026-11-14', 'starts_at' => '2026-11-14 '.$start, 'ends_at' => '2026-11-14 '.$end,
        'timezone' => $business->timezone, 'status' => $status, 'subtotal_minor' => 500000, 'total_minor' => 500000, 'currency' => 'NPR',
    ]);
}

function addBookingStaff(Business $business, Service $service): BusinessUser
{
    $membership = BusinessUser::query()->create([
        'business_id' => $business->id, 'user_id' => User::factory()->create()->id, 'role' => 'staff', 'is_active' => true,
    ]);
    $membership->services()->attach($service);
    foreach (range(0, 6) as $weekday) {
        $membership->hours()->create(['weekday' => $weekday, 'opens_at' => '09:00', 'closes_at' => '17:00', 'is_closed' => false]);
    }

    return $membership;
}

function bookingApprover(Business $business): User
{
    $owner = $business->members()->wherePivot('role', 'owner')->wherePivot('is_active', true)->first();
    if ($owner !== null) {
        return $owner;
    }
    $owner = User::factory()->create();
    BusinessUser::query()->create(['business_id' => $business->id, 'user_id' => $owner->id, 'role' => 'owner', 'is_active' => true]);

    return $owner;
}
