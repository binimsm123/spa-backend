<?php

use App\Models\User;
use App\Services\AvailabilityCache;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Support\ApiException;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

it('shares cached availability between customers and refreshes it after twenty seconds', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $membership = $service->staffMemberships()->firstOrFail();
    $url = "/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}";
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson($url)->assertOk()
        ->assertJsonPath('data.availability.0.slots.0', ['starts_at' => '09:00', 'ends_at' => '10:00']);
    $membership->hours()->update(['opens_at' => '11:00']);

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson($url)->assertOk()
        ->assertJsonPath('data.availability.0.slots.0', ['starts_at' => '09:00', 'ends_at' => '10:00']);
    $this->travel(21)->seconds();
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.availability.0.slots.0', ['starts_at' => '11:00', 'ends_at' => '12:00']);
});

it('performs no schedule queries when availability is cached', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $availability = app(AvailabilityService::class);
    $first = $availability->availableDatesFor($business, $service);
    $this->expectsDatabaseQueryCount(0);

    $cached = $availability->availableDatesFor($business, $service);

    expect($cached)->toEqual($first);
});

it('caches empty availability to avoid repeated searches', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBusinessWithService();
    $availability = app(AvailabilityService::class);
    $availability->availableDatesFor($business, $service);
    $this->expectsDatabaseQueryCount(0);

    $cached = $availability->availableDatesFor($business, $service);

    expect($cached['dates'])->toBeEmpty();
    expect($cached['availability'])->toBeEmpty();
});

it('filters elapsed cached slots and replaces an exhausted date with the next available date', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 15:59:50', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $availability = app(AvailabilityService::class);
    $first = $availability->availableDatesFor($business, $service);
    expect($first['availability']->first())->toBe(['date' => '2026-11-03', 'slots' => [
        ['starts_at' => '16:00', 'ends_at' => '17:00'],
    ]]);
    $this->travel(10)->seconds();
    $this->expectsDatabaseQueryCount(0);

    $cached = $availability->availableDatesFor($business, $service);

    expect($cached['dates']->all())->toBe(['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08']);
    expect($cached['message'])->toBe('Available booking dates loaded.');
});

it('validates party size and service status even when availability was cached', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $url = "/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}";
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson($url)->assertOk();

    $this->getJson($url.'&people_count=3')->assertConflict()->assertJsonPath('code', 'CAPACITY_EXCEEDED');
    $service->update(['is_bookable' => false]);
    $this->getJson($url)->assertConflict()->assertJsonPath('code', 'SERVICE_NOT_BOOKABLE');
});

it('rechecks database capacity when confirming a time advertised in the cache', function (): void {
    $this->travelTo(Carbon::parse('2026-11-14 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $bookings = app(BookingService::class);
    $payload = ['business_id' => $business->id, 'service_id' => $service->id, 'requested_time' => '09:00', 'requested_date' => '2026-11-14'];
    $firstRequest = $bookings->createRequest($first, $payload);
    $secondRequest = $bookings->createRequest($second, $payload);
    $url = "/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}";
    $this->actingAs($first, 'sanctum')->getJson($url)->assertOk();
    $bookings->approveRequest(bookingApprover($business), $firstRequest);
    $this->actingAs($second, 'sanctum')->getJson($url)->assertOk()
        ->assertJsonPath('data.availability.0.slots.0.starts_at', '09:00');

    $this->actingAs(bookingApprover($business), 'sanctum')->postJson("/api/v1/business/{$business->id}/bookings/requests/{$secondRequest->id}/approve")
        ->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT');
    $this->assertDatabaseCount('bookings', 1);
});

it('rechecks the cache after acquiring the rebuild lock', function (): void {
    $store = Cache::store('array');
    $key = 'availability-test:'.Str::uuid();
    $calls = 0;
    Event::listen(CacheMissed::class, function (CacheMissed $event) use ($store, $key): void {
        if ($event->key === $key) {
            $store->put($key, ['availability' => []], 20);
        }
    });

    $result = app(AvailabilityCache::class)->remember($key, function () use (&$calls): array {
        $calls++;

        return ['unexpected' => true];
    });

    expect($result)->toBe(['availability' => []]);
    expect($calls)->toBe(0);
});

it('returns a retryable error instead of rebuilding while another request owns the lock', function (): void {
    config(['spa.booking.availability_lock_wait_seconds' => 0]);
    $key = 'availability-test:'.Str::uuid();
    $lock = Cache::store('array')->lock($key.':rebuild', 30);
    expect($lock->get())->toBeTrue();
    $calls = 0;
    try {
        expect(fn () => app(AvailabilityCache::class)->remember($key, function () use (&$calls): array {
            $calls++;

            return [];
        }))->toThrow(ApiException::class, 'Availability is being refreshed. Please try again shortly.');
        expect($calls)->toBe(0);
    } finally {
        $lock->release();
    }
});

it('releases the rebuild lock and avoids caching a failed calculation', function (): void {
    $key = 'availability-test:'.Str::uuid();
    $cache = app(AvailabilityCache::class);

    expect(fn () => $cache->remember($key, fn () => throw ApiException::notFound('Unavailable.')))
        ->toThrow(ApiException::class, 'Unavailable.');
    $result = $cache->remember($key, fn (): array => ['available' => true]);

    expect($result)->toBe(['available' => true]);
});

it('returns a clear 429 response when availability is still being rebuilt', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    config(['spa.booking.availability_lock_wait_seconds' => 0]);
    [$business, $service] = createBookableBusinessWithService();
    $url = "/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}";
    $key = null;
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$key): void {
        if (str_starts_with($event->key, 'availability:v1:')) {
            $key = $event->key;
        }
    });
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson($url)->assertOk();
    Cache::store('array')->forget($key);
    $lock = Cache::store('array')->lock($key.':rebuild', 30);
    expect($lock->get())->toBeTrue();

    try {
        $this->getJson($url)->assertTooManyRequests()->assertExactJson([
            'success' => false,
            'message' => 'Availability is being refreshed. Please try again shortly.',
            'code' => 'THROTTLED',
        ]);
    } finally {
        $lock->release();
    }
});
