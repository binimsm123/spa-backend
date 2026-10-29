<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Support\ApiException;
use Illuminate\Support\Str;

it('returns a clear 409 instead of creating a duplicate booking even when the request status is inconsistent', function (string $status): void {
    $this->freezeTime();
    $user = User::factory()->create();
    [$business, $service] = createBusinessWithService();
    $bookings = app(BookingService::class);
    $bookingRequest = $bookings->createRequest($user, [
        'business_id' => $business->getKey(),
        'service_id' => $service->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ]);
    $bookings->proposeTimes($bookingRequest, ['10:00', '14:00'], $user);
    $firstTime = $bookingRequest->times()->whereTime('starts_at', '10:00')->firstOrFail();
    $otherTime = $bookingRequest->times()->whereTime('starts_at', '14:00')->firstOrFail();
    $booking = $bookings->confirmRequest($user, $bookingRequest, $firstTime->getKey());
    $bookingRequest->refresh()->update(['status' => $status]);
    $notificationCount = $user->notifications()->count();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/requests/'.$bookingRequest->getKey().'/confirm', [
            'time_id' => $otherTime->getKey(),
        ])->assertConflict()->assertExactJson([
            'success' => false,
            'message' => 'This request already has a booking. Please view your existing booking.',
            'code' => 'BOOKING_REQUEST_ALREADY_BOOKED',
        ]);

    $this->assertDatabaseCount('bookings', 1);
    $this->assertDatabaseCount('booking_items', 1);
    $this->assertDatabaseCount('booking_status_history', 1);
    $this->assertDatabaseHas('bookings', [
        'id' => $booking->getKey(),
        'starts_at' => now()->addDay()->format('Y-m-d').' 10:00:00',
    ]);
    expect($user->notifications()->count())->toBe($notificationCount);
})->with(['confirmed', 'times_proposed']);

it('reloads the request before confirming a stale instance', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    [$business, $service] = createBusinessWithService();
    $bookings = app(BookingService::class);
    $bookingRequest = $bookings->createRequest($user, [
        'business_id' => $business->getKey(),
        'service_id' => $service->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ]);
    $bookings->proposeTimes($bookingRequest, ['10:00'], $user);
    $bookingRequest->refresh();
    $timeId = $bookingRequest->times()->firstOrFail()->getKey();
    $bookingRequest->fresh()->update(['status' => 'declined']);

    expect(fn () => $bookings->confirmRequest($user, $bookingRequest, $timeId))
        ->toThrow(ApiException::class, 'Confirm the request only after times are proposed and available.');

    $this->assertDatabaseCount('bookings', 0);
});

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

it('retains the selected service price and duration through availability proposal and confirmation', function (): void {
    $user = User::factory()->create();
    [$business, $service] = createBusinessWithService();
    $price = $service->prices()->create(['price_minor' => 750000, 'currency' => 'NPR', 'duration' => '01:30', 'is_current' => true]);
    $date = now()->addDay()->toDateString();
    $this->actingAs($user, 'sanctum');

    $this->getJson("/api/v1/businesses/{$business->id}/services/{$service->id}/availability?date={$date}&service_price_id={$price->id}")
        ->assertOk()->assertJsonPath('data.dates.0.slots.0.ends_at', '10:30')
        ->assertJsonPath('data.service.price', 7500);
    $response = $this->postJson('/api/v1/bookings/requests', [
        'business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $price->id,
        'requested_date' => $date, 'people_count' => 2,
    ])->assertAccepted()->assertJsonPath('data.service_price_id', $price->id);
    $request = BookingRequest::query()->findOrFail($response->json('data.booking_request_id'));
    expect($request->servicePrice->id)->toBe($price->id);
    $this->getJson('/api/v1/bookings/requests/'.$request->id)->assertOk()->assertJsonPath('data.service_price_id', $price->id);

    app(BookingService::class)->proposeTimes($request, ['10:00'], $user);
    $time = $request->refresh()->times()->firstOrFail();
    expect($time->ends_at->format('H:i'))->toBe('11:30');
    $booking = app(BookingService::class)->confirmRequest($user, $request, $time->id);

    expect($booking->subtotal_minor)->toBe(1500000)
        ->and($booking->items->first()->duration_minutes_snapshot)->toBe(90)
        ->and($booking->items->first()->unit_price_minor)->toBe(750000);
});

it('rejects invalid service price selections without creating a booking request', function (string $kind): void {
    $user = User::factory()->create();
    [$business, $service, $price] = createBusinessWithService();
    if ($kind === 'other service') {
        [, , $price] = createBusinessWithService();
    } elseif ($kind === 'inactive') {
        $price->update(['is_current' => false]);
    }
    $priceId = match ($kind) {
        'missing' => (string) Str::ulid(),
        'malformed' => 'invalid',
        default => $price->id,
    };

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings/requests', [
        'business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $priceId,
        'requested_date' => now()->addDay()->toDateString(),
    ])->assertUnprocessable()->assertJsonValidationErrors('service_price_id');
    $this->assertDatabaseCount('booking_requests', 0);
})->with(['other service', 'inactive', 'missing', 'malformed']);

it('checks slot conflicts using the selected service price duration', function (): void {
    $user = User::factory()->create();
    [$business, $service] = createBusinessWithService();
    $price = $service->prices()->create(['price_minor' => 750000, 'currency' => 'NPR', 'duration' => '01:30', 'is_current' => true]);
    $date = now()->addDay()->toDateString();
    $bookings = app(BookingService::class);
    $request = $bookings->createRequest($user, [
        'business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $price->id, 'requested_date' => $date,
    ]);
    $bookings->proposeTimes($request, ['10:00'], $user);
    Booking::query()->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'appointment_date' => $date,
        'starts_at' => $date.' 11:00:00', 'ends_at' => $date.' 12:00:00', 'status' => 'confirmed',
        'subtotal_minor' => 500000, 'total_minor' => 500000, 'currency' => 'NPR',
    ]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings/requests/'.$request->id.'/confirm', [
        'time_id' => $request->refresh()->times()->firstOrFail()->id,
    ])->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT');
    $this->assertDatabaseCount('bookings', 1);
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

    // Business proposes 10:00 on the requested date (UTC hours 09:00-17:00).
    app(BookingService::class)->proposeTimes($bookingRequest, ['10:00'], $user);

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

    // First customer books 10:00.
    $reqA = $serviceObj->createRequest($userA, [
        'service_id' => $service->getKey(),
        'business_id' => $business->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ]);
    $serviceObj->proposeTimes($reqA, ['10:00'], $userA);
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
        ->assertJsonPath('data.service.service_prices.0.duration', '01:00')
        ->assertJsonMissingPath('data.service.duration_minutes');

    $business->closures()->create(['starts_on' => $date, 'ends_on' => $date]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.dates.0.is_closed', true)->assertJsonCount(0, 'data.dates.0.slots');
});
