<?php

use App\Models\BusinessUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

it('returns the next five booking dates in the standard response format', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->hours()->update(['closes_at' => '12:00']);
    $fullDaySlots = [
        ['starts_at' => '09:00', 'ends_at' => '10:00'],
        ['starts_at' => '09:30', 'ends_at' => '10:30'],
        ['starts_at' => '10:00', 'ends_at' => '11:00'],
        ['starts_at' => '10:30', 'ends_at' => '11:30'],
        ['starts_at' => '11:00', 'ends_at' => '12:00'],
    ];

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates?service_id={$service->getKey()}")
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Available booking dates loaded.',
            'data' => [
                'business_id' => $business->getKey(),
                'timezone' => 'UTC',
                'dates' => ['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07'],
                'availability' => [
                    ['date' => '2026-11-03', 'slots' => [
                        ['starts_at' => '10:30', 'ends_at' => '11:30'],
                        ['starts_at' => '11:00', 'ends_at' => '12:00'],
                    ]],
                    ['date' => '2026-11-04', 'slots' => $fullDaySlots],
                    ['date' => '2026-11-05', 'slots' => $fullDaySlots],
                    ['date' => '2026-11-06', 'slots' => $fullDaySlots],
                    ['date' => '2026-11-07', 'slots' => $fullDaySlots],
                ],
            ],
        ]);
});

it('skips weekly closed days and still returns five booking dates', function (bool $isOnline, array $expectedDates): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->update(['is_online' => $isOnline]);
    $business->hours()->whereIn('weekday', [0, 3])->update(['is_closed' => true]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates?service_id={$service->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.dates', $expectedDates);
})->with([
    'online today' => [true, ['2026-11-03', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-09']],
    'offline today' => [false, ['2026-11-05', '2026-11-06', '2026-11-07', '2026-11-09', '2026-11-10']],
]);

it('skips overlapping closure ranges including their start and end dates', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->closures()->createMany([
        ['starts_on' => '2026-11-03', 'ends_on' => '2026-11-06'],
        ['starts_on' => '2026-11-06', 'ends_on' => '2026-11-08'],
    ]);
    [$otherBusiness] = createBusinessWithService();
    $otherBusiness->closures()->create(['starts_on' => '2026-11-09', 'ends_on' => '2026-11-30']);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates?service_id={$service->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13']);
});

it('skips offline today and both closure ranges and weekly closed days', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->update(['is_online' => false]);
    $business->hours()->whereIn('weekday', [0, 6])->update(['is_closed' => true]);
    $business->closures()->create(['starts_on' => '2026-11-04', 'ends_on' => '2026-11-06']);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates?service_id={$service->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13']);
});

it('returns five booking dates even when the business opens only once a week', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->hours()->where('weekday', '!=', 3)->update(['is_closed' => true]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates?service_id={$service->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-04', '2026-11-11', '2026-11-18', '2026-11-25', '2026-12-02']);
});

it('uses the business timezone to determine the first booking date', function (bool $isOnline, array $expectedDates): void {
    $this->travelTo(Carbon::parse('2026-11-03 23:30:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->update(['timezone' => 'Asia/Kathmandu', 'is_online' => $isOnline]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates?service_id={$service->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Kathmandu')
        ->assertJsonPath('data.dates', $expectedDates)
        ->assertJsonPath('data.availability.0.date', $expectedDates[0])
        ->assertJsonPath('data.availability.0.slots.0', ['starts_at' => '09:00', 'ends_at' => '10:00']);
})->with([
    'online today' => [true, ['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08']],
    'offline today' => [false, ['2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09']],
]);

it('returns booking dates according to the business availability', function (array $attributes, array $expectedDates, string $message): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->update($attributes);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates?service_id={$service->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.dates', $expectedDates)
        ->assertJsonCount(count($expectedDates), 'data.availability')
        ->assertJsonPath('message', $message);
})->with([
    'offline business skips only today' => [['is_online' => false], ['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'], 'This business is offline today. Available booking dates loaded.'],
    'inactive business' => [['status' => 'paused'], [], 'This business is not accepting bookings at the moment. Please check again later.'],
]);

it('hides only today after the owner switches the business offline', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $owner = User::factory()->create();
    $customer = User::factory()->create();
    BusinessUser::query()->create([
        'business_id' => $business->getKey(),
        'user_id' => $owner->getKey(),
        'role' => 'owner',
        'is_active' => true,
    ]);
    $url = "/api/v1/businesses/{$business->getKey()}/available-dates?service_id={$service->getKey()}";
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
    [$business, $service] = createBookableBusinessWithService();
    $business->hours()->update($attributes);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates?service_id={$service->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.dates', [])
        ->assertJsonPath('data.availability', [])
        ->assertJsonPath('message', 'This business has no available opening hours. Please contact the business for availability.');
})->with([
    'closed every day' => [['is_closed' => true]],
    'missing opening time' => [['opens_at' => null]],
    'missing closing time' => [['closes_at' => null]],
    'no opening interval' => [['opens_at' => '09:00', 'closes_at' => '09:00']],
    'invalid opening interval' => [['opens_at' => '17:00', 'closes_at' => '09:00']],
]);

it('returns no booking dates when opening hours are missing', function (): void {
    [$business, $service] = createBookableBusinessWithService();
    $business->hours()->delete();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->getKey()}/available-dates?service_id={$service->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.dates', [])
        ->assertJsonPath('data.availability', [])
        ->assertJsonPath('message', 'This business has no available opening hours. Please contact the business for availability.');
});

it('returns 401 when booking dates are requested without authentication', function (): void {
    [$business, $service] = createBookableBusinessWithService();

    $this->getJson("/api/v1/businesses/{$business->getKey()}/available-dates?service_id={$service->getKey()}")
        ->assertUnauthorized();
});

it('returns 404 when booking dates are requested for an unknown business', function (): void {
    $businessId = Str::ulid();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$businessId}/available-dates")
        ->assertNotFound()
        ->assertExactJson([
            'success' => false,
            'message' => 'This business could not be found. Please choose another business.',
            'code' => 'NOT_FOUND',
        ]);
});

it('returns 422 when the selected service is missing or malformed', function (array $query, string $message): void {
    [$business] = createBusinessWithService();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?".http_build_query($query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['service_id' => $message]);
})->with([
    'missing service' => [[], 'Please choose a service to see available booking dates.'],
    'malformed service' => [['service_id' => 'invalid'], 'The selected service is invalid. Please choose another service.'],
]);

it('returns 404 when the selected service is unavailable at this business', function (string $reason): void {
    [$business, $service] = createBusinessWithService();
    [$otherBusiness, $otherService] = createBusinessWithService();
    $serviceId = match ($reason) {
        'other business' => $otherService->id,
        'unknown service' => (string) Str::ulid(),
        'deleted service' => $service->id,
    };
    if ($reason === 'deleted service') {
        $service->delete();
    }

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$serviceId}")
        ->assertNotFound()
        ->assertExactJson([
            'success' => false,
            'message' => 'The selected service is not available at this business. Please choose another service.',
            'code' => 'NOT_FOUND',
        ]);
})->with(['other business', 'unknown service', 'deleted service']);

it('returns 422 when the party size is invalid', function (string $peopleCount, string $message): void {
    [$business, $service] = createBusinessWithService();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}&people_count={$peopleCount}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['people_count' => $message]);
})->with([
    'zero' => ['0', 'At least one person is required for a booking.'],
    'fractional' => ['1.5', 'Please enter a whole number of people.'],
    'too many' => ['21', 'You can book for up to 20 people per request.'],
]);

it('returns 409 when the selected party size exceeds service capacity', function (): void {
    [$business, $service] = createBookableBusinessWithService();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}&people_count=3")
        ->assertConflict()->assertJsonPath('code', 'CAPACITY_EXCEEDED')
        ->assertJsonPath('message', 'The requested party size exceeds service capacity.');
});

it('returns 409 when the selected service cannot be booked online', function (array $attributes): void {
    [$business, $service] = createBusinessWithService();
    $service->update($attributes);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertConflict()->assertJsonPath('code', 'SERVICE_NOT_BOOKABLE')
        ->assertJsonPath('message', 'This service cannot be booked online.');
})->with([
    'not bookable' => [['is_bookable' => false]],
    'inactive service' => [['status' => 'paused']],
]);

it('returns 422 when the selected duration is not a current price for this service', function (string $reason): void {
    [$business, $service, $price] = createBusinessWithService();
    [$otherBusiness, $otherService, $otherPrice] = createBusinessWithService();
    if ($reason === 'historical price') {
        $price->update(['is_current' => false]);
    }
    $priceId = match ($reason) {
        'other service' => $otherPrice->id,
        'historical price' => $price->id,
        'unknown price' => (string) Str::ulid(),
    };

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}&service_price_id={$priceId}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['service_price_id' => 'The selected service option is no longer available. Please choose another duration.']);
})->with(['other service', 'historical price', 'unknown price']);

it('filters dates by the selected service duration and accepts the maximum service party size', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service, $shortPrice] = createBookableBusinessWithService();
    $longPrice = $service->prices()->create(['duration' => '02:00', 'price_minor' => 900000, 'currency' => 'NPR', 'is_current' => true]);
    $business->hours()->where('weekday', 2)->update(['opens_at' => '09:00', 'closes_at' => '10:00']);
    $url = "/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}&people_count=2";
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson($url."&service_price_id={$longPrice->id}")->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'])
        ->assertJsonPath('data.availability.0.date', '2026-11-04')
        ->assertJsonPath('data.availability.0.slots.0', ['starts_at' => '09:00', 'ends_at' => '11:00'])
        ->assertJsonCount(7, 'data.availability.0.slots');
    $this->getJson($url."&service_price_id={$shortPrice->id}")->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07'])
        ->assertJsonPath('data.availability.0', ['date' => '2026-11-03', 'slots' => [
            ['starts_at' => '09:00', 'ends_at' => '10:00'],
        ]]);
});

it('skips today when no future interval can fit the complete service', function (string $now): void {
    $this->travelTo(Carbon::parse('2026-11-03 '.$now, 'UTC'));
    [$business, $service] = createBookableBusinessWithService();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08']);
})->with(['16:00', '18:00']);

it('skips dates without a full available staff interval', function (string $reason): void {
    $this->travelTo(Carbon::parse('2026-11-14 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $membership = $service->staffMemberships()->firstOrFail();
    match ($reason) {
        'leave' => $membership->leaves()->create(['starts_on' => '2026-11-14', 'ends_on' => '2026-11-14']),
        'break' => $membership->hours()->where('weekday', 6)->update(['breaks' => [['starts_at' => '09:00', 'ends_at' => '17:00']]]),
        'short shift' => $membership->hours()->where('weekday', 6)->update(['opens_at' => '09:00', 'closes_at' => '09:30']),
        'fully booked' => automaticBooking($business, $membership->user_id, '09:00', '17:00'),
    };

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19']);
})->with(['leave', 'break', 'short shift', 'fully booked']);

it('returns no dates without enough active qualified scheduled staff', function (string $reason): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $membership = $service->staffMemberships()->firstOrFail();
    match ($reason) {
        'unqualified' => $membership->services()->detach(),
        'inactive membership' => $membership->update(['is_active' => false]),
        'inactive account' => $membership->user->update(['is_active' => false]),
        'no schedule' => $membership->hours()->delete(),
        'insufficient team' => $service->update(['required_staff_count' => 2]),
    };

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertOk()->assertJsonPath('data.dates', [])
        ->assertJsonPath('data.availability', [])
        ->assertJsonPath('message', 'No booking dates are available for this service in the next 30 days. Please try another service or check again later.');
})->with(['unqualified', 'inactive membership', 'inactive account', 'no schedule', 'insufficient team']);

it('requires the complete service team to be available on each date', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $service->update(['required_staff_count' => 2]);
    $second = addBookingStaff($business, $service);
    $second->hours()->where('weekday', 3)->update(['is_closed' => true]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertOk()
        ->assertJsonPath('data.dates', ['2026-11-03', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08']);
});

it('returns only dates within the thirty day search horizon', function (string $closureEnd, array $dates, string $message): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->closures()->create(['starts_on' => '2026-11-03', 'ends_on' => $closureEnd]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertOk()->assertJsonPath('data.dates', $dates)
        ->assertJsonCount(count($dates), 'data.availability')
        ->assertJsonPath('message', $message);
})->with([
    'one date' => ['2026-12-01', ['2026-12-02'], 'Only 1 booking date is available for this service in the next 30 days.'],
    'two dates' => ['2026-11-30', ['2026-12-01', '2026-12-02'], 'Only 2 booking dates are available for this service in the next 30 days.'],
    'three dates' => ['2026-11-29', ['2026-11-30', '2026-12-01', '2026-12-02'], 'Only 3 booking dates are available for this service in the next 30 days.'],
    'four dates' => ['2026-11-28', ['2026-11-29', '2026-11-30', '2026-12-01', '2026-12-02'], 'Only 4 booking dates are available for this service in the next 30 days.'],
    'no dates' => ['2026-12-02', [], 'No booking dates are available for this service in the next 30 days. Please try another service or check again later.'],
]);

it('explains when an offline business has no future booking dates', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->update(['is_online' => false]);
    $business->closures()->create(['starts_on' => '2026-11-04', 'ends_on' => '2026-12-03']);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.dates', [])
        ->assertJsonPath('data.availability', [])
        ->assertJsonPath('message', 'This business is offline today. No booking dates are available for this service in the next 30 days. Please try another service or check again later.');
});

it('explains when an offline business has limited future booking dates', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $business->update(['is_online' => false]);
    $business->closures()->create(['starts_on' => '2026-11-04', 'ends_on' => '2026-12-01']);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertOk()->assertJsonPath('data.dates', ['2026-12-02'])
        ->assertJsonPath('data.availability.0.date', '2026-12-02')
        ->assertJsonPath('data.availability.0.slots.0', ['starts_at' => '09:00', 'ends_at' => '10:00'])
        ->assertJsonPath('message', 'This business is offline today. Only 1 booking date is available for this service in the next 30 days.');
});

it('returns a helpful 404 when the service has no current booking price', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service, $price] = createBookableBusinessWithService();
    $price->update(['is_current' => false]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertNotFound()->assertJsonPath('code', 'SERVICE_UNAVAILABLE')
        ->assertJsonPath('message', 'This service is currently unavailable for booking. Please choose another service or check again later.');
});

it('returns a helpful 422 when the selected service duration is malformed', function (): void {
    [$business, $service] = createBusinessWithService();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}&service_price_id=invalid")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['service_price_id' => 'The selected service option is invalid. Please choose another duration.']);
});

it('returns validation messages when a duration is supplied without a valid service selection', function (array $selection, string $message): void {
    [$business, $service, $price] = createBusinessWithService();
    $query = http_build_query($selection + ['service_price_id' => $price->id]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['service_id' => $message]);
})->with([
    'missing service' => [[], 'Please choose a service to see available booking dates.'],
    'non-scalar service' => [['service_id' => ['invalid']], 'The selected service is invalid. Please choose another service.'],
]);

it('returns time slots excluding staff breaks leave and existing bookings', function (): void {
    [$business, $service, $membership] = automaticAvailabilityFixture();
    $this->travelTo(Carbon::parse('2026-11-14 08:00', 'UTC'));
    $membership->leaves()->create(['starts_on' => '2026-11-14', 'ends_on' => '2026-11-14', 'starts_at' => '14:00', 'ends_at' => '14:30']);
    automaticBooking($business, $membership->user_id, '15:00', '16:00');

    $this->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}&people_count=2")
        ->assertOk()->assertJsonCount(5, 'data.availability')
        ->assertJsonPath('data.availability.0', [
            'date' => '2026-11-14',
            'slots' => [
                ['starts_at' => '10:00', 'ends_at' => '11:00'],
                ['starts_at' => '10:30', 'ends_at' => '11:30'],
                ['starts_at' => '11:00', 'ends_at' => '12:00'],
                ['starts_at' => '13:00', 'ends_at' => '14:00'],
            ],
        ]);
});

it('returns only time slots that can accommodate the complete service team', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    [$business, $service] = createBookableBusinessWithService();
    $service->update(['required_staff_count' => 2]);
    $business->hours()->update(['closes_at' => '12:00']);
    $second = addBookingStaff($business, $service);
    $second->leaves()->create(['starts_on' => '2026-11-03', 'ends_on' => '2026-11-03', 'starts_at' => '09:00', 'ends_at' => '10:30']);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}")
        ->assertOk()->assertJsonPath('data.availability.0', [
            'date' => '2026-11-03',
            'slots' => [
                ['starts_at' => '10:30', 'ends_at' => '11:30'],
                ['starts_at' => '11:00', 'ends_at' => '12:00'],
            ],
        ]);
});
