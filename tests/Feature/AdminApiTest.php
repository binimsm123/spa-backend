<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\Review;
use App\Models\RewardConfiguration;
use App\Support\AuditLogger;

it('blocks non-admin users from admin routes', function (): void {
    [$customer] = actingAsCustomer();

    $this->actingAs($customer, 'sanctum')
        ->getJson('/api/v1/admin/dashboard')
        ->assertStatus(403);
});

it('blocks unauthenticated access to admin routes', function (): void {
    $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
});

it('shows platform overview to a superadmin', function (): void {
    actingAsSuperadmin();

    createBusinessWithService();

    $this->getJson('/api/v1/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['businesses', 'customers', 'bookings', 'payments', 'offers', 'rewards']]);
});

it('lists users and disables an account with audit trail', function (): void {
    $admin = actingAsSuperadmin();
    [$customer] = actingAsCustomer();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/users?q='.urlencode($customer->display_name))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$customer->getKey()}/status", [
            'is_active' => false,
            'reason' => 'Test suspension',
        ])->assertOk();

    expect($customer->refresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'user.disabled')->exists())->toBeTrue();
});

it('verifies and suspends businesses with audit trail', function (): void {
    $admin = actingAsSuperadmin();
    [$business] = createBusinessWithService();

    $business->forceFill(['is_verified' => false, 'status' => 'pending'])->save();

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/businesses/{$business->getKey()}/state", ['action' => 'verify'])
        ->assertOk();

    expect($business->refresh()->is_verified)->toBeTrue()->and($business->status)->toBe('active');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/businesses/{$business->getKey()}/state", ['action' => 'suspend', 'reason' => 'Policy violation'])
        ->assertOk();

    expect($business->refresh()->status)->toBe('suspended')
        ->and(AuditLog::query()->where('action', 'business.suspend')->exists())->toBeTrue();
});

it('creates platform-sponsored offers as admin', function (): void {
    $admin = actingAsSuperadmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/offers', [
            'code' => 'PLATFORM10',
            'title' => 'Platform 10% off',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'starts_at' => now()->subDay()->toIso8601String(),
            'expires_at' => now()->addWeek()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.code', 'PLATFORM10');

    expect(Offer::query()->where('code', 'PLATFORM10')->first()->is_platform_sponsored)->toBeTrue();
});

it('moderates reviews by hiding and restoring', function (): void {
    $admin = actingAsSuperadmin();
    [$customer] = actingAsCustomer();

    [$business, $service] = createBusinessWithService();

    $booking = Booking::query()->create([
        'user_id' => $customer->getKey(),
        'business_id' => $business->getKey(),
        'appointment_date' => now()->toDateString(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'status' => 'completed',
        'completed_at' => now(),
        'subtotal_minor' => 100000,
        'total_minor' => 100000,
        'currency' => 'NPR',
    ]);

    $review = Review::query()->create([
        'booking_id' => $booking->getKey(),
        'user_id' => $customer->getKey(),
        'business_id' => $business->getKey(),
        'service_id' => $service->getKey(),
        'rating' => 2,
        'comments' => 'Meh',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/reviews/{$review->getKey()}/hide", ['reason' => 'Spam content'])
        ->assertOk();

    expect($review->refresh()->is_hidden)->toBeTrue();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/reviews/{$review->getKey()}/restore")
        ->assertOk();

    expect($review->refresh()->is_hidden)->toBeFalse();
});

it('creates a new reward configuration without overwriting history', function (): void {
    $admin = actingAsSuperadmin();

    RewardConfiguration::query()->create(['points_per_rupee' => 1, 'basis' => 'subtotal', 'is_active' => true]);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/rewards/configurations', [
            'points_per_rupee' => 2,
            'basis' => 'post_discount',
        ])->assertCreated();

    $configs = RewardConfiguration::query()->orderBy('created_at')->get();

    expect($configs)->toHaveCount(2)
        ->and($configs->first()->is_active)->toBeFalse() // old config deactivated, not overwritten
        ->and($configs->last()->points_per_rupee)->toBe('2.0000');
});

it('makes controlled reward adjustments with a mandatory reason', function (): void {
    $admin = actingAsSuperadmin();
    [$customer] = actingAsCustomer();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/users/{$customer->getKey()}/rewards/adjust", [
            'points' => 250,
            'reason' => 'Goodwill compensation for service issue',
        ])->assertOk();

    expect($customer->refresh()->reward_points)->toBe(250);

    // Missing reason is rejected.
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/users/{$customer->getKey()}/rewards/adjust", ['points' => 100])
        ->assertStatus(422);
});

it('returns audit logs with actor details', function (): void {
    $admin = actingAsSuperadmin();

    AuditLogger::record($admin, 'test.action', 'test', '123', null, null, 'because');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/audit-logs?action=test.action')
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('includes current service prices in admin service lists and updates', function (): void {
    actingAsSuperadmin();
    [$business, $service] = createBusinessWithService();

    $this->getJson('/api/v1/admin/services?business_id='.$business->getKey())->assertOk()
        ->assertJsonPath('data.items.0.service_prices.0.duration', '01:00:00')
        ->assertJsonPath('data.items.0.service_prices.0.price_minor', 500000)
        ->assertJsonMissingPath('data.items.0.duration_minutes');

    $this->patchJson('/api/v1/admin/services/'.$service->getKey(), ['is_bookable' => false])->assertOk()
        ->assertJsonPath('data.service_prices.0.duration', '01:00:00')
        ->assertJsonMissingPath('data.duration_minutes');
    $this->assertDatabaseHas('services', ['id' => $service->getKey(), 'is_bookable' => false]);
});
