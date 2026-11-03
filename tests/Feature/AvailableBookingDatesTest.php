<?php

use App\Models\BusinessUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

it('returns the next five booking dates in the standard response format', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business] = createBusinessWithService();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates")
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Available booking dates loaded.',
            'data' => [
                'business_id' => $business->getKey(),
                'timezone' => 'UTC',
                'dates' => ['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07'],
            ],
        ]);
});

it('skips weekly closed days and still returns five booking dates', function (bool $isOnline, array $expectedDates): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business] = createBusinessWithService();
    $business->update(['is_online' => $isOnline]);
    $business->hours()->whereIn('weekday', [0, 3])->update(['is_closed' => true]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates")
        ->assertOk()
        ->assertJsonPath('data.dates', $expectedDates);
})->with([
    'online today' => [true, ['2026-11-03', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-09']],
    'offline today' => [false, ['2026-11-05', '2026-11-06', '2026-11-07', '2026-11-09', '2026-11-10']],
]);

it('skips overlapping closure ranges including their start and end dates', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business] = createBusinessWithService();
    $business->closures()->createMany([
        ['starts_on' => '2026-11-03', 'ends_on' => '2026-11-06'],
        ['starts_on' => '2026-11-06', 'ends_on' => '2026-11-08'],
    ]);
    [$otherBusiness] = createBusinessWithService();
    $otherBusiness->closures()->create(['starts_on' => '2026-11-09', 'ends_on' => '2026-11-30']);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates")
        ->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13']);
});

it('skips offline today and both closure ranges and weekly closed days', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business] = createBusinessWithService();
    $business->update(['is_online' => false]);
    $business->hours()->whereIn('weekday', [0, 6])->update(['is_closed' => true]);
    $business->closures()->create(['starts_on' => '2026-11-04', 'ends_on' => '2026-11-06']);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates")
        ->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13']);
});

it('returns five booking dates even when the business opens only once a week', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business] = createBusinessWithService();
    $business->hours()->where('weekday', '!=', 3)->update(['is_closed' => true]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates")
        ->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-04', '2026-11-11', '2026-11-18', '2026-11-25', '2026-12-02']);
});

it('uses the business timezone to determine the first booking date', function (bool $isOnline, array $expectedDates): void {
    $this->travelTo(Carbon::parse('2026-11-03 23:30:00', 'UTC'));
    [$business] = createBusinessWithService();
    $business->update(['timezone' => 'Asia/Kathmandu', 'is_online' => $isOnline]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates")
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Kathmandu')
        ->assertJsonPath('data.dates', $expectedDates);
})->with([
    'online today' => [true, ['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08']],
    'offline today' => [false, ['2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09']],
]);

it('returns booking dates according to the business availability', function (array $attributes, array $expectedDates): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business] = createBusinessWithService();
    $business->update($attributes);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates")
        ->assertOk()
        ->assertJsonPath('data.dates', $expectedDates);
})->with([
    'offline business skips only today' => [['is_online' => false], ['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08']],
    'inactive business' => [['status' => 'paused'], []],
]);

it('hides only today after the owner switches the business offline', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business] = createBusinessWithService();
    $owner = User::factory()->create();
    $customer = User::factory()->create();
    BusinessUser::query()->create([
        'business_id' => $business->getKey(),
        'user_id' => $owner->getKey(),
        'role' => 'owner',
        'is_active' => true,
    ]);
    $url = "/api/v1/businesses/{$business->getKey()}/available-dates";
    $this->actingAs($customer, 'sanctum')->getJson($url)->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07']);

    $this->actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}/toggleStatus")
        ->assertOk()->assertJsonPath('message', 'Business is offline.');

    $this->actingAs($customer, 'sanctum')->getJson($url)->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08']);
    $this->assertDatabaseHas('businesses', ['id' => $business->getKey(), 'is_online' => false]);
});

it('returns no booking dates without usable opening hours', function (array $attributes): void {
    [$business] = createBusinessWithService();
    $business->hours()->update($attributes);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates")
        ->assertOk()
        ->assertJsonPath('data.dates', []);
})->with([
    'closed every day' => [['is_closed' => true]],
    'missing opening time' => [['opens_at' => null]],
    'missing closing time' => [['closes_at' => null]],
    'no opening interval' => [['opens_at' => '09:00', 'closes_at' => '09:00']],
    'invalid opening interval' => [['opens_at' => '17:00', 'closes_at' => '09:00']],
]);

it('returns no booking dates when opening hours are missing', function (): void {
    [$business] = createBusinessWithService();
    $business->hours()->delete();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates")
        ->assertOk()
        ->assertJsonPath('data.dates', []);
});

it('returns 401 when booking dates are requested without authentication', function (): void {
    [$business] = createBusinessWithService();

    $this->getJson("/api/v1/businesses/{$business->getKey()}/available-dates")
        ->assertUnauthorized();
});

it('returns 404 when booking dates are requested for an unknown business', function (): void {
    $businessId = Str::ulid();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$businessId}/available-dates")
        ->assertNotFound();
});
