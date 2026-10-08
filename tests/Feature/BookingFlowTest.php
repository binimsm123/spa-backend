<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Support\ApiException;
use Illuminate\Support\Str;

it('returns available slots for a service and blocks past dates', function (): void {
    [$user] = actingAsCustomer();
    [$business, $service] = createBusinessWithService();

    $tomorrow = now()->addDay()->toDateString();

    $this->getJson("/api/v1/businesses/{$service->business_id}/services/{$service->getKey()}/availability?business_id={$business->getKey()}&date={$tomorrow}&timezone=UTC")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['dates' => [['date', 'slots']]]]);

    // Past date is rejected.
    $this->getJson("/api/v1/businesses/{$service->business_id}/services/{$service->getKey()}/availability?business_id={$business->getKey()}&date=2020-01-01&timezone=UTC")
        ->assertStatus(422);
});

it('creates a booking request idempotently with the Idempotency-Key header', function (): void {
    [$user] = actingAsCustomer();
    [$business, $service] = createBusinessWithService();

    $payload = [
        'service_id' => $service->getKey(),
        'business_id' => $business->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ];

    $key = 'test-key-'.Str::random(12);

    $first = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/requests', $payload, ['Idempotency-Key' => $key])
        ->assertStatus(202);

    $second = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/requests', $payload, ['Idempotency-Key' => $key])
        ->assertStatus(202);

    expect($first->json('data.booking_request_id'))->toBe($second->json('data.booking_request_id'))
        ->and(BookingRequest::query()->count())->toBe(1);
});

it('runs the full request -> propose -> confirm -> booking flow', function (): void {
    [$user] = actingAsCustomer();
    [$business, $service] = createBusinessWithService();

    $bookingRequest = app(BookingService::class)->createRequest($user, [
        'service_id' => $service->getKey(),
        'business_id' => $business->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ]);

    // Business proposes 10:00 tomorrow (UTC hours 09:00-17:00).
    $start = now()->addDay()->setTimeFromTimeString('10:00')->format('Y-m-d\TH:i:s');

    app(BookingService::class)->proposeTimes($bookingRequest, [$start], $user);

    $timeId = $bookingRequest->refresh()->times->first()->getKey();

    $booking = app(BookingService::class)->confirmRequest($user, $bookingRequest->refresh(), $timeId);

    expect($booking->status)->toBe(BookingStatus::AwaitingPayment)
        ->and($booking->subtotal_minor)->toBe(500000)
        ->and($booking->items()->count())->toBe(1)
        ->and($booking->items->first()->service_name_snapshot)->toBe($service->name);

    // Booking request is now confirmed; a second confirm attempt fails.
    $this->expectException(ApiException::class);
    app(BookingService::class)->confirmRequest($user, $bookingRequest->refresh(), $timeId);
});

it('detects slot conflicts between two bookings at the same business', function (): void {
    [$userA] = actingAsCustomer();
    [$userB] = actingAsCustomer();
    [$business, $service] = createBusinessWithService();

    $serviceObj = app(BookingService::class);
    $tomorrow10 = now()->addDay()->setTimeFromTimeString('10:00')->format('Y-m-d\TH:i:s');

    // First customer books 10:00.
    $reqA = $serviceObj->createRequest($userA, [
        'service_id' => $service->getKey(),
        'business_id' => $business->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ]);
    $serviceObj->proposeTimes($reqA, [$tomorrow10], $userA);
    $bookingA = $serviceObj->confirmRequest($userA, $reqA->refresh(), $reqA->times->first()->getKey());

    expect($bookingA->status->value)->toBe('awaiting_payment');

    // Second customer requests the same day: 10:00 must not be offered again.
    $reqB = $serviceObj->createRequest($userB, [
        'service_id' => $service->getKey(),
        'business_id' => $business->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ]);

    $slots = app(AvailabilityService::class)->slotsFor(
        $service,
        $business,
        now()->addDay()->startOfDay(),
    );

    expect($slots->pluck('starts_at'))->not->toContain('10:00');
});

it('prevents customers from viewing other customers bookings', function (): void {
    [$userA] = actingAsCustomer();
    [$userB] = actingAsCustomer();

    [$business, $service] = createBusinessWithService();

    $booking = Booking::query()->create([
        'user_id' => $userA->getKey(),
        'business_id' => $business->getKey(),
        'appointment_date' => now()->toDateString(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'status' => 'confirmed',
        'subtotal_minor' => 100000,
        'total_minor' => 100000,
        'currency' => 'NPR',
    ]);

    $this->actingAs($userB, 'sanctum')
        ->getJson("/api/v1/bookings/{$booking->getKey()}")
        ->assertStatus(403);
});

it('rejects capacity above the service max_people', function (): void {
    [$user] = actingAsCustomer();
    [$business, $service] = createBusinessWithService();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/requests', [
            'service_id' => $service->getKey(),
            'business_id' => $business->getKey(),
            'requested_date' => now()->addDay()->toDateString(),
            'people_count' => 5, // max_people is 2
        ])->assertStatus(409)->assertJsonPath('success', false);
});

it('rejects booking a service under another business with 404', function (): void {
    actingAsCustomer();
    [$business, $service] = createBusinessWithService();
    [$other] = createBusinessWithService();

    $this->postJson('/api/v1/bookings/requests', [
        'service_id' => $service->getKey(),
        'business_id' => $other->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ])->assertNotFound();
    $this->assertDatabaseCount('booking_requests', 0);

    $this->getJson("/api/v1/businesses/{$other->getKey()}/services/{$service->getKey()}/availability?date=".now()->addDay()->toDateString())
        ->assertNotFound();
});

it('uses business hours and timezone for availability without a location parameter', function (): void {
    actingAsCustomer();
    [$business, $service] = createBusinessWithService();
    $date = now()->addDay()->toDateString();
    $url = "/api/v1/businesses/{$business->getKey()}/services/{$service->getKey()}/availability?date={$date}";

    $this->getJson($url)->assertOk()->assertJsonPath('data.timezone', 'UTC')
        ->assertJsonPath('data.dates.0.slots.0.starts_at', '09:00')
        ->assertJsonPath('data.dates.0.slots.0.ends_at', '10:00')
        ->assertJsonPath('data.service.service_prices.0.duration', '01:00:00')
        ->assertJsonMissingPath('data.service.duration_minutes');

    $business->closures()->create(['starts_on' => $date, 'ends_on' => $date]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.dates.0.is_closed', true)->assertJsonCount(0, 'data.dates.0.slots');
});
