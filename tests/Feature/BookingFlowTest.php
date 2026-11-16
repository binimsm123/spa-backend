<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\User;
use App\Services\BookingService;
use App\Support\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

it('returns a clear 409 instead of creating a duplicate booking even when the request status is inconsistent', function (string $status): void {
    $this->freezeTime();
    $user = User::factory()->create();
    [$business, $service] = createBookableBusinessWithService();
    $bookings = app(BookingService::class);
    $bookingRequest = $bookings->createRequest($user, [
        'business_id' => $business->getKey(),
        'service_id' => $service->getKey(),
        'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
    ]);
    $booking = $bookings->approveRequest(bookingApprover($business), $bookingRequest);
    $bookingRequest->refresh()->update(['status' => $status]);
    $notificationCount = $user->notifications()->count();

    $this->actingAs(bookingApprover($business), 'sanctum')
        ->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$bookingRequest->getKey().'/approve', [])->assertConflict()->assertExactJson([
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
})->with(['approved', 'pending']);

it('reloads the request before confirming a stale instance', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    [$business, $service] = createBookableBusinessWithService();
    $bookings = app(BookingService::class);
    $bookingRequest = $bookings->createRequest($user, [
        'business_id' => $business->getKey(),
        'service_id' => $service->getKey(),
        'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
    ]);
    $bookingRequest->refresh();
    $bookingRequest->fresh()->update(['status' => 'rejected']);

    expect(fn () => $bookings->approveRequest(bookingApprover($business), $bookingRequest))
        ->toThrow(ApiException::class, 'Only pending booking requests can be approved.');

    $this->assertDatabaseCount('bookings', 0);
});

it('returns available slots for a service and blocks past dates', function (): void {
    [$user] = actingAsCustomer();
    [$business, $service] = createBookableBusinessWithService();

    $tomorrow = now()->addDay()->toDateString();

    $this->getJson("/api/v1/businesses/{$service->business_id}/services/{$service->getKey()}/availability?business_id={$business->getKey()}&date={$tomorrow}&timezone=UTC")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['dates' => [['date', 'slots']]]]);

    // Past date is rejected.
    $this->getJson("/api/v1/businesses/{$service->business_id}/services/{$service->getKey()}/availability?business_id={$business->getKey()}&date=2020-01-01&timezone=UTC")
        ->assertStatus(422);
});

it('returns 404 without service data when availability is requested for an unavailable business', function (array $attributes): void {
    $this->freezeTime();
    [$business, $service] = createBookableBusinessWithService();
    $business->update($attributes);
    $date = now()->addDay()->toDateString();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/services/{$service->getKey()}/availability?date={$date}")
        ->assertNotFound()
        ->assertJsonMissingPath('data.service');
})->with([
    'offline business' => [['is_online' => false]],
    'inactive business' => [['status' => 'paused']],
]);

it('shows only future start times for today', function (string $currentTime, array $expectedSlots): void {
    $this->travelTo(Carbon::parse('2026-11-03 '.$currentTime, 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->hours()->update(['closes_at' => '12:00']);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/services/{$service->getKey()}/availability?date=2026-11-03")
        ->assertOk()
        ->assertJsonPath('data.dates.0.slots', $expectedSlots);
})->with([
    'before the next slot' => ['09:50:00', [
        ['starts_at' => '10:00', 'ends_at' => '11:00'],
        ['starts_at' => '10:30', 'ends_at' => '11:30'],
        ['starts_at' => '11:00', 'ends_at' => '12:00'],
    ]],
    'exactly at a slot start' => ['10:00:00', [
        ['starts_at' => '10:30', 'ends_at' => '11:30'],
        ['starts_at' => '11:00', 'ends_at' => '12:00'],
    ]],
    'after closing' => ['12:00:00', []],
]);

it('shows future slots for the business local today when UTC has reached tomorrow', function (): void {
    $this->travelTo(Carbon::parse('2026-11-04 00:00:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->update(['timezone' => 'America/Los_Angeles']);
    $business->hours()->update(['closes_at' => '18:00']);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/services/{$service->getKey()}/availability?date=2026-11-03")
        ->assertOk()
        ->assertJsonPath('data.dates.0.slots', [
            ['starts_at' => '16:30', 'ends_at' => '17:30'],
            ['starts_at' => '17:00', 'ends_at' => '18:00'],
        ]);
});

it('accepts booking requests for today in the business timezone', function (string $currentTime, string $timezone, string $requestedDate): void {
    $this->travelTo(Carbon::parse($currentTime, 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->update(['timezone' => $timezone]);
    $business->hours()->update(['closes_at' => '20:00']);
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/v1/bookings/requests', [
            'business_id' => $business->getKey(),
            'service_id' => $service->getKey(),
            'requested_time' => '17:00', 'requested_date' => $requestedDate,
        ])
        ->assertAccepted()
        ->assertJsonPath('data.requested_date', $requestedDate);

    $this->assertDatabaseHas('booking_requests', [
        'user_id' => $customer->getKey(),
        'business_id' => $business->getKey(),
        'requested_time' => '17:00', 'requested_date' => $requestedDate,
    ]);
})->with([
    'UTC today' => ['2026-11-03 10:00:00', 'UTC', '2026-11-03'],
    'local date behind UTC' => ['2026-11-04 00:00:00', 'America/Los_Angeles', '2026-11-03'],
    'local date ahead of UTC' => ['2026-11-03 23:30:00', 'Asia/Kathmandu', '2026-11-04'],
]);

it('keeps the selected request time visible without generating replacement proposals', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $customer = User::factory()->create();
    $request = app(BookingService::class)->createRequest($customer, ['business_id' => $business->id,
        'service_id' => $service->id, 'requested_date' => '2026-11-03', 'requested_time' => '10:00']);
    $this->travel(3)->hours();

    $this->actingAs($customer, 'sanctum')->getJson('/api/v1/bookings/requests/'.$request->id)
        ->assertOk()->assertJsonPath('data.requested_time', '10:00')->assertJsonMissingPath('data.available_times');
    $this->assertDatabaseCount('booking_request_times', 0);
});

it('rejects booking requests for yesterday in the business timezone', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 23:30:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->update(['timezone' => 'Asia/Kathmandu']);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/v1/bookings/requests', [
            'business_id' => $business->getKey(),
            'service_id' => $service->getKey(),
            'requested_time' => '10:00', 'requested_date' => '2026-11-03',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('requested_date');

    $this->assertDatabaseCount('booking_requests', 0);
});

it('retains the selected service price and duration through availability request and approval', function (): void {
    $user = User::factory()->create();
    [$business, $service] = createBookableBusinessWithService();
    $price = $service->prices()->create(['price_minor' => 750000, 'currency' => 'NPR', 'duration' => '01:30', 'is_current' => true]);
    $date = now()->addDay()->toDateString();
    $this->actingAs($user, 'sanctum');

    $this->getJson("/api/v1/businesses/{$business->id}/services/{$service->id}/availability?date={$date}&service_price_id={$price->id}")
        ->assertOk()->assertJsonPath('data.dates.0.slots.0.ends_at', '10:30')
        ->assertJsonPath('data.service.price', 7500);
    $response = $this->postJson('/api/v1/bookings/requests', [
        'business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $price->id,
        'requested_time' => '10:00', 'requested_date' => $date, 'people_count' => 2,
    ])->assertAccepted()->assertJsonPath('data.service_price_id', $price->id);
    $request = BookingRequest::query()->findOrFail($response->json('data.booking_request_id'));
    expect($request->servicePrice->id)->toBe($price->id);
    $this->getJson('/api/v1/bookings/requests/'.$request->id)->assertOk()->assertJsonPath('data.service_price_id', $price->id);

    $booking = app(BookingService::class)->approveRequest(bookingApprover($business), $request);

    expect($booking->subtotal_minor)->toBe(1500000)
        ->and($booking->items->first()->duration_minutes_snapshot)->toBe(90)
        ->and($booking->items->first()->unit_price_minor)->toBe(750000);
});

it('rejects invalid service price selections without creating a booking request', function (string $kind): void {
    $user = User::factory()->create();
    [$business, $service, $price] = createBookableBusinessWithService();
    if ($kind === 'other service') {
        [, , $price] = createBookableBusinessWithService();
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
        'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
    ])->assertUnprocessable()->assertJsonValidationErrors('service_price_id');
    $this->assertDatabaseCount('booking_requests', 0);
})->with(['other service', 'inactive', 'missing', 'malformed']);

it('checks slot conflicts using the selected service price duration', function (): void {
    $user = User::factory()->create();
    [$business, $service] = createBookableBusinessWithService();
    $price = $service->prices()->create(['price_minor' => 750000, 'currency' => 'NPR', 'duration' => '01:30', 'is_current' => true]);
    $date = now()->addDay()->toDateString();
    $bookings = app(BookingService::class);
    $request = $bookings->createRequest($user, [
        'business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $price->id, 'requested_time' => '10:00', 'requested_date' => $date,
    ]);
    Booking::query()->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'appointment_date' => $date,
        'starts_at' => $date.' 11:00:00', 'ends_at' => $date.' 12:00:00', 'status' => 'confirmed',
        'subtotal_minor' => 500000, 'total_minor' => 500000, 'currency' => 'NPR', 'timezone' => $business->timezone,
    ]);

    $this->actingAs(bookingApprover($business), 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [])->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT');
    $this->assertDatabaseCount('bookings', 1);
});

it('creates a booking request idempotently with the Idempotency-Key header', function (): void {
    [$user] = actingAsCustomer();
    [$business, $service] = createBookableBusinessWithService();

    $payload = [
        'service_id' => $service->getKey(),
        'business_id' => $business->getKey(),
        'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
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

it('runs the full request -> pending request -> business approval -> booking flow', function (): void {
    [$user] = actingAsCustomer();
    [$business, $service] = createBookableBusinessWithService();

    $bookingRequest = app(BookingService::class)->createRequest($user, [
        'service_id' => $service->getKey(),
        'business_id' => $business->getKey(),
        'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
    ]);

    $booking = app(BookingService::class)->approveRequest(bookingApprover($business), $bookingRequest->refresh());

    expect($booking->status)->toBe(BookingStatus::AwaitingPayment)
        ->and($booking->subtotal_minor)->toBe(500000)
        ->and($booking->items()->count())->toBe(1)
        ->and($booking->items->first()->service_name_snapshot)->toBe($service->name);

    // Booking request is now confirmed; a second confirm attempt fails.
    $this->expectException(ApiException::class);
    app(BookingService::class)->approveRequest(bookingApprover($business), $bookingRequest->refresh());
});

it('detects slot conflicts between two requests approved at the same business', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $bookings = app(BookingService::class);
    $owner = bookingApprover($business);
    $payload = ['business_id' => $business->id, 'service_id' => $service->id,
        'requested_date' => '2026-11-04', 'requested_time' => '10:00'];
    $first = $bookings->createRequest(User::factory()->create(), $payload);
    $second = $bookings->createRequest(User::factory()->create(), $payload);
    $bookings->approveRequest($owner, $first);

    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$second->id.'/approve')
        ->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT');
    $this->assertDatabaseCount('bookings', 1);
});

it('prevents customers from viewing other customers bookings', function (): void {
    [$userA] = actingAsCustomer();
    [$userB] = actingAsCustomer();

    [$business, $service] = createBookableBusinessWithService();

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
    [$business, $service] = createBookableBusinessWithService();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/requests', [
            'service_id' => $service->getKey(),
            'business_id' => $business->getKey(),
            'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
            'people_count' => 5, // max_people is 2
        ])->assertStatus(409)->assertJsonPath('success', false);
});

it('rejects booking a service under another business with 404', function (): void {
    actingAsCustomer();
    [$business, $service] = createBookableBusinessWithService();
    [$other] = createBookableBusinessWithService();

    $this->postJson('/api/v1/bookings/requests', [
        'service_id' => $service->getKey(),
        'business_id' => $other->getKey(),
        'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
    ])->assertNotFound();
    $this->assertDatabaseCount('booking_requests', 0);

    $this->getJson("/api/v1/businesses/{$other->getKey()}/services/{$service->getKey()}/availability?date=".now()->addDay()->toDateString())
        ->assertNotFound();
});

it('uses business hours and timezone for availability without a location parameter', function (): void {
    actingAsCustomer();
    [$business, $service] = createBookableBusinessWithService();
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
