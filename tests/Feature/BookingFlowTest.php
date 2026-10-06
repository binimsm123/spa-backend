<?php

use App\Services\BookingService;
use Illuminate\Support\Facades\{Schema};
use Illuminate\Support\Str;

it('returns available slots for a service and blocks past dates', function (): void {
    [$user] = actingAsCustomer();
    [, $branch, $service] = createBusinessWithService();

    $tomorrow = now()->addDay()->toDateString();

    $this->getJson("/api/v1/businesses/{$service->business_id}/services/{$service->getKey()}/availability?business_location_id={$branch->getKey()}&date={$tomorrow}&timezone=UTC", )
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['dates' => [['date', 'slots']]]]);

    // Past date is rejected.
    $this->getJson("/api/v1/businesses/{$service->business_id}/services/{$service->getKey()}/availability?business_location_id={$branch->getKey()}&date=2020-01-01&timezone=UTC")
        ->assertStatus(422);
});

it('creates a booking request idempotently with the Idempotency-Key header', function (): void {
    [$user] = actingAsCustomer();
    [$business, $branch, $service] = createBusinessWithService();

    $payload = [
        'service_id' => $service->getKey(),
        'business_location_id' => $branch->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ];

    $key = 'test-key-'.Str::random(12);

    $first = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/requests', $payload, ['Idempotency-Key' => $key])
        ->assertStatus(202);

    $second = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/requests', $payload, ['Idempotency-Key' => $key])
        ->assertStatus(202);

    expect($first->json('data.id'))->toBe($second->json('data.id'))
        ->and(\App\Models\BookingRequest::query()->count())->toBe(1);
});

it('runs the full request -> propose -> confirm -> booking flow', function (): void {
    [$user] = actingAsCustomer();
    [$business, $branch, $service] = createBusinessWithService();

    $bookingRequest = app(BookingService::class)->createRequest($user, [
        'service_id' => $service->getKey(),
        'business_location_id' => $branch->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ]);

    // Business proposes 10:00 tomorrow (UTC hours 09:00-17:00).
    $start = now()->addDay()->setTimeFromTimeString('10:00')->format('Y-m-d\TH:i:s');

    app(BookingService::class)->proposeTimes($bookingRequest, [$start], $user);

    $timeId = $bookingRequest->refresh()->times->first()->getKey();

    $booking = app(BookingService::class)->confirmRequest($user, $bookingRequest->refresh(), $timeId);

    expect($booking->status)->toBe(\App\Enums\BookingStatus::AwaitingPayment)
        ->and($booking->subtotal_minor)->toBe(500000)
        ->and($booking->items()->count())->toBe(1)
        ->and($booking->items->first()->service_name_snapshot)->toBe($service->name);

    // Booking request is now confirmed; a second confirm attempt fails.
    $this->expectException(\App\Support\ApiException::class);
    app(BookingService::class)->confirmRequest($user, $bookingRequest->refresh(), $timeId);
});

it('detects slot conflicts between two bookings at the same branch', function (): void {
    [$userA] = actingAsCustomer();
    [$userB] = actingAsCustomer();
    [$business, $branch, $service] = createBusinessWithService();

    $serviceObj = app(BookingService::class);
    $tomorrow10 = now()->addDay()->setTimeFromTimeString('10:00')->format('Y-m-d\TH:i:s');

    // First customer books 10:00.
    $reqA = $serviceObj->createRequest($userA, [
        'service_id' => $service->getKey(),
        'business_location_id' => $branch->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ]);
    $serviceObj->proposeTimes($reqA, [$tomorrow10], $userA);
    $bookingA = $serviceObj->confirmRequest($userA, $reqA->refresh(), $reqA->times->first()->getKey());

    expect($bookingA->status->value)->toBe('awaiting_payment');

    // Second customer requests the same day: 10:00 must not be offered again.
    $reqB = $serviceObj->createRequest($userB, [
        'service_id' => $service->getKey(),
        'business_location_id' => $branch->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ]);

    $slots = app(\App\Services\AvailabilityService::class)->slotsFor(
        $service,
        $branch,
        now()->addDay()->startOfDay(),
    );

    expect($slots->pluck('starts_at'))->not->toContain('10:00');
});

it('prevents customers from viewing other customers bookings', function (): void {
    [$userA] = actingAsCustomer();
    [$userB] = actingAsCustomer();

    [$business, $branch, $service] = createBusinessWithService();

    $booking = \App\Models\Booking::query()->create([
        'user_id' => $userA->getKey(),
        'business_id' => $business->getKey(),
        'business_location_id' => $branch->getKey(),
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
    [$business, $branch, $service] = createBusinessWithService();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/requests', [
            'service_id' => $service->getKey(),
            'business_location_id' => $branch->getKey(),
            'requested_date' => now()->addDay()->toDateString(),
            'people_count' => 5, // max_people is 2
        ])->assertStatus(409)->assertJsonPath('success', false);
});
