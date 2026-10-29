<?php

use App\Models\BusinessUser;
use App\Models\Offer;
use App\Models\ServicePrice;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Support\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function createBusinessOwner(): array
{
    [$owner] = actingAsCustomer(['display_name' => 'Owner '.Str::random(4)]);
    $owner->assignRole('owner');

    [$business] = createBusinessWithService(['name' => 'Owner Spa '.Str::random(4)]);

    BusinessUser::query()->create([
        'business_id' => $business->getKey(),
        'user_id' => $owner->getKey(),
        'role' => 'owner',
        'is_active' => true,
    ]);

    return [$owner, $business];
}

it('persists the business rejection reason and returns it to the business and customer', function (?string $reason): void {
    $this->freezeTime();
    [$owner, $business] = createBusinessOwner();
    $customer = User::factory()->create();
    $service = $business->services()->firstOrFail();
    $bookingRequest = app(BookingService::class)->createRequest($customer, [
        'business_id' => $business->getKey(),
        'service_id' => $service->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ]);
    $url = '/api/v1/business/'.$business->getKey().'/bookings/requests';

    $this->postJson($url.'/'.$bookingRequest->getKey().'/decline', ['reason' => $reason])
        ->assertOk()
        ->assertJsonPath('data.status', 'declined')
        ->assertJsonPath('data.decline_reason', $reason);

    $this->assertDatabaseHas('booking_requests', [
        'id' => $bookingRequest->getKey(),
        'status' => 'declined',
        'decline_reason' => $reason,
    ]);
    $this->assertDatabaseHas('notifications', [
        'user_id' => $customer->getKey(),
        'title' => 'Booking request declined',
        'body' => $business->name.' cannot serve your request.'.($reason !== null ? ' Reason: '.$reason : ''),
    ]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.items.0.decline_reason', $reason);
    $this->actingAs($customer, 'sanctum')
        ->getJson('/api/v1/bookings/requests/'.$bookingRequest->getKey())
        ->assertOk()
        ->assertJsonPath('data.status', 'declined')
        ->assertJsonPath('data.decline_reason', $reason);
})->with([
    'with reason' => ['No appointments available on the requested date.'],
    'without reason' => [null],
]);

it('lists only the price selected for each booking request even when requests share a service', function (): void {
    [$owner, $business] = createBusinessOwner();
    $service = $business->services()->firstOrFail();
    $firstPrice = $service->prices()->firstOrFail();
    $secondPrice = $service->prices()->create(['price_minor' => 750000, 'currency' => 'NPR', 'duration' => '01:30', 'is_current' => true]);
    $expectedPrices = [];
    foreach ([$firstPrice, $secondPrice] as $price) {
        $bookingRequest = app(BookingService::class)->createRequest($owner, [
            'business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $price->id,
            'requested_date' => now()->addDay()->toDateString(),
        ]);
        $expectedPrices[$bookingRequest->id] = $price->id;
    }
    $firstPrice->update(['is_current' => false]);

    $response = $this->getJson('/api/v1/business/'.$business->id.'/bookings/requests')
        ->assertOk()->assertJsonCount(2, 'data.items');

    foreach ($response->json('data.items') as $item) {
        expect($item['service']['service_prices'])->toHaveCount(1)
            ->and($item['service']['service_prices'][0]['id'])->toBe($expectedPrices[$item['id']]);
    }
});

it('returns no unrelated prices for a legacy request without a selected price', function (): void {
    [$owner, $business] = createBusinessOwner();
    $service = $business->services()->firstOrFail();
    $bookingRequest = app(BookingService::class)->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id,
        'requested_date' => now()->addDay()->toDateString(),
    ]);
    $bookingRequest->update(['service_price_id' => null]);

    $this->getJson('/api/v1/business/'.$business->id.'/bookings/requests')->assertOk()
        ->assertJsonCount(0, 'data.items.0.service.service_prices');
});

it('returns only the selected price when declining a booking request', function (): void {
    [$owner, $business] = createBusinessOwner();
    $service = $business->services()->firstOrFail();
    $price = $service->prices()->create(['price_minor' => 750000, 'currency' => 'NPR', 'duration' => '01:30', 'is_current' => true]);
    $date = now()->addDay()->toDateString();
    $bookingRequest = app(BookingService::class)->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $price->id, 'requested_date' => $date,
    ]);

    $this->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$bookingRequest->id.'/decline', [
        'starts_at' => ['10:00'],
    ])->assertOk()->assertJsonCount(1, 'data.service.service_prices')
        ->assertJsonPath('data.service.service_prices.0.id', $price->id);
});

it('proposes time-only options on the requested date with the selected service duration', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0, 37));
    [$owner, $business] = createBusinessOwner();
    $business->update(['timezone' => 'Asia/Kathmandu']);
    $service = $business->services()->firstOrFail();
    $price = $service->prices()->create(['price_minor' => 750000, 'currency' => 'NPR', 'duration' => '01:30', 'is_current' => true]);
    $bookingRequest = app(BookingService::class)->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $price->id,
        'requested_date' => '2026-10-30',
    ]);

    $this->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$bookingRequest->id.'/propose', [
        'starts_at' => ['10:00', '14:00'],
    ])->assertOk()->assertExactJson([
        'success' => true,
        'message' => 'Times proposed.',
        'data' => ['10:00', '14:00'],
    ]);

    foreach (['10:00:00' => '11:30:00', '14:00:00' => '15:30:00'] as $start => $end) {
        $this->assertDatabaseHas('booking_request_times', [
            'booking_request_id' => $bookingRequest->id,
            'starts_at' => '2026-10-30 '.$start, 'ends_at' => '2026-10-30 '.$end, 'is_available' => true,
        ]);
    }
    expect($bookingRequest->refresh()->status->value)->toBe('times_proposed');
    $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'title' => 'Times available']);
});

it('uses the request timezone when calculating proposed time durations across daylight saving', function (): void {
    $this->travelTo(now()->setDate(2027, 3, 13)->setTime(12, 0));
    [$owner, $business] = createBusinessOwner();
    $business->hours()->update(['opens_at' => '00:00']);
    $service = $business->services()->firstOrFail();
    $price = $service->prices()->create(['price_minor' => 750000, 'currency' => 'NPR', 'duration' => '01:30', 'is_current' => true]);
    $bookingRequest = app(BookingService::class)->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $price->id,
        'requested_date' => '2027-03-14', 'timezone' => 'America/New_York',
    ]);

    $this->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$bookingRequest->id.'/propose', [
        'starts_at' => ['01:30'],
    ])->assertOk()->assertExactJson([
        'success' => true,
        'message' => 'Times proposed.',
        'data' => ['01:30'],
    ]);

    $this->assertDatabaseHas('booking_request_times', [
        'booking_request_id' => $bookingRequest->id,
        'starts_at' => '2027-03-14 01:30:00', 'ends_at' => '2027-03-14 04:00:00',
    ]);
});

it('rejects invalid proposed times without updating the request or notifying the customer', function (array $payload, string $field): void {
    $this->freezeTime();
    [$owner, $business] = createBusinessOwner();
    $service = $business->services()->firstOrFail();
    $bookingRequest = app(BookingService::class)->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id,
        'requested_date' => now()->addDay()->toDateString(),
    ]);

    $this->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$bookingRequest->id.'/propose', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($bookingRequest->refresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('booking_request_times', 0);
    $this->assertDatabaseMissing('notifications', ['user_id' => $owner->id, 'title' => 'Times available']);
})->with([
    'missing times' => [[], 'starts_at'],
    'empty times' => [['starts_at' => []], 'starts_at'],
    'full datetime' => [['starts_at' => ['2026-10-31T10:00:00+05:45']], 'starts_at.0'],
    'seconds' => [['starts_at' => ['10:00:00']], 'starts_at.0'],
    'invalid hour' => [['starts_at' => ['24:00']], 'starts_at.0'],
    'invalid minute' => [['starts_at' => ['10:60']], 'starts_at.0'],
    'missing leading zero' => [['starts_at' => ['9:00']], 'starts_at.0'],
    'outside opening hours' => [['starts_at' => ['10:00', '18:00']], 'starts_at'],
]);

it('rejects duplicate proposed times with a validation message and no inserts', function (): void {
    $this->freezeTime();
    [$owner, $business] = createBusinessOwner();
    $service = $business->services()->firstOrFail();
    $bookingRequest = app(BookingService::class)->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id,
        'requested_date' => now()->addDay()->toDateString(),
    ]);

    $this->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$bookingRequest->id.'/propose', [
        'starts_at' => ['10:00', '10:00'],
    ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors(['starts_at.0' => 'Each proposed time must be unique.']);

    $this->assertDatabaseCount('booking_request_times', 0);
    expect($bookingRequest->refresh()->status->value)->toBe('pending');
    $this->assertDatabaseMissing('notifications', ['user_id' => $owner->id, 'title' => 'Times available']);
});

it('rejects an already saved proposed time without inserting other times', function (): void {
    $this->freezeTime();
    [$owner, $business] = createBusinessOwner();
    $service = $business->services()->firstOrFail();
    $date = now()->addDay()->toDateString();
    $bookingRequest = app(BookingService::class)->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id, 'requested_date' => $date,
    ]);
    $bookingRequest->times()->create([
        'starts_at' => $date.' 10:00:00', 'ends_at' => $date.' 11:00:00', 'is_available' => true,
    ]);

    $this->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$bookingRequest->id.'/propose', [
        'starts_at' => ['14:00', '10:00'],
    ])->assertUnprocessable()->assertJsonPath('message', 'One or more proposed times already exist for this booking request.')
        ->assertJsonValidationErrors('starts_at');

    $this->assertDatabaseCount('booking_request_times', 1);
    expect($bookingRequest->refresh()->status->value)->toBe('pending');
    $this->assertDatabaseMissing('notifications', ['user_id' => $owner->id, 'title' => 'Times available']);
});

it('rejects a stale proposal after another submission has proposed times', function (): void {
    $this->freezeTime();
    [$owner, $business] = createBusinessOwner();
    $service = $business->services()->firstOrFail();
    $bookings = app(BookingService::class);
    $bookingRequest = $bookings->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id,
        'requested_date' => now()->addDay()->toDateString(),
    ]);
    $bookings->proposeTimes($bookingRequest, ['10:00'], $owner);

    expect(fn () => $bookings->proposeTimes($bookingRequest, ['10:00'], $owner))
        ->toThrow(ApiException::class, 'Times can only be proposed for pending requests.');

    $this->assertDatabaseCount('booking_request_times', 1);
    expect($owner->notifications()->where('title', 'Times available')->count())->toBe(1);
});

it('lists only businesses the user manages', function (): void {
    [$owner, $business] = createBusinessOwner();
    createBusinessWithService(['name' => 'Other Spa']); // not managed by owner

    $response = $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/business/my')
        ->assertOk()
        ->assertJsonMissingPath('data.items.0.is_insured');

    $names = collect($response->json('data.items'))->pluck('name');

    expect($names)->toContain($business->name)
        ->and($names)->not->toContain('Other Spa');
});

it('blocks non-members from managing a business', function (): void {
    [$owner, $business] = createBusinessOwner();
    [$outsider] = actingAsCustomer();

    $this->actingAs($outsider, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}", ['name' => 'Hacked Name'])
        ->assertStatus(403);
});

it('updates business profile and online state', function (): void {
    [$owner, $business] = createBusinessOwner();

    $this->actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}", [
            'about' => 'Updated about text.',
            'home_service' => true,
            'location_service' => false,
            'staff_number' => 8,
            'is_online' => false,
        ])->assertOk()
        ->assertJsonPath('data.home_service', true)
        ->assertJsonPath('data.location_service', false)
        ->assertJsonPath('data.staff_number', 8);

    expect($business->refresh()->about)->toBe('Updated about text.')
        ->and($business->is_online)->toBeFalse()
        ->and($business->home_service)->toBeTrue()
        ->and($business->location_service)->toBeFalse()
        ->and($business->staff_number)->toBe(8);
});

it('updates weekly hours for a business', function (): void {
    [$owner, $business] = createBusinessOwner();

    $hours = collect(range(0, 6))->map(fn (int $d) => [
        'weekday' => $d,
        'opens_at' => $d === 0 ? null : '08:00',
        'closes_at' => $d === 0 ? null : '14:00',
        'is_closed' => $d === 0,
    ])->all();

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/business/{$business->getKey()}/hours", ['hours' => $hours])
        ->assertOk();

    expect($business->refresh()->hours->first(fn ($h) => $h->weekday === 1)->opens_at?->format('H:i'))->toBe('08:00');
});

it('creates services with prices and preserves price history on update', function (): void {
    [$owner, $business] = createBusinessOwner();

    $serviceId = $this->actingAs($owner, 'sanctum')
        ->postJson("/api/v1/business/{$business->getKey()}/services", [
            'name' => 'Hot Stone Therapy',
            'duration' => '01:15',
            'max_people' => 1,
            'price_minor' => 600000,
            'currency' => 'NPR',
        ])->assertCreated()->json('data.id');

    expect(ServicePrice::query()->where('service_id', $serviceId)->where('is_current', true)->value('price_minor'))->toBe(600000);

    // Price change: history is preserved, a new current row is inserted.
    $this->actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}/services/{$serviceId}", ['price_minor' => 650000])
        ->assertOk();

    $prices = ServicePrice::query()->where('service_id', $serviceId)->orderBy('created_at')->get();

    expect($prices)->toHaveCount(2)
        ->and($prices->first()->is_current)->toBeFalse()
        ->and($prices->last()->price_minor)->toBe(650000)
        ->and($prices->last()->durationMinutes())->toBe(75);
});

it('invites staff by mobile number and deactivates them', function (): void {
    [$owner, $business] = createBusinessOwner();

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/v1/business/{$business->getKey()}/staff/invite", [
            'mobile_number' => '+97798'.random_int(7000000, 9999999),
            'role' => 'staff',
        ])->assertCreated()->assertJsonPath('success', true);

    expect($business->members()->count())->toBe(2); // owner + invited staff
});

it('manages business offers with usage counters', function (): void {
    [$owner, $business] = createBusinessOwner();

    $offerId = $this->actingAs($owner, 'sanctum')
        ->postJson("/api/v1/business/{$business->getKey()}/offers", [
            'code' => 'OWN10',
            'title' => 'Owner 10% off',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'starts_at' => now()->subDay()->toIso8601String(),
            'expires_at' => now()->addWeek()->toIso8601String(),
        ])->assertCreated()->json('data.id');

    $this->actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}/offers/{$offerId}", ['status' => 'paused'])
        ->assertOk();

    expect(Offer::query()->find($offerId)->status->value)->toBe('paused');
});

it('stores business profile location details in business locations', function (): void {
    [$owner, $business] = createBusinessOwner();

    $payload = [
        'address' => 'Street 12',
        'city' => 'Lalitpur',
        'latitude' => 27.6787,
        'longitude' => 85.3162,
        'timezone' => 'Asia/Kathmandu',
    ];

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/business/{$business->getKey()}", $payload)
        ->assertOk()->assertJsonPath('data.address', 'Street 12')->assertJsonPath('data.timezone', 'Asia/Kathmandu');
    $this->assertDatabaseHas('business_locations', ['business_id' => $business->getKey(), ...Arr::except($payload, ['timezone'])]);
    $this->assertDatabaseHas('businesses', ['id' => $business->getKey(), 'timezone' => 'Asia/Kathmandu']);

    $response = $this->getJson("/api/v1/business/{$business->getKey()}")->assertOk()
        ->assertJsonCount(7, 'data.hours')->assertJsonPath('data.city', 'Lalitpur');
    expect($response->json('data'))->not->toHaveKeys(['branches', 'primary_branch']);
});

it('rejects invalid business location details with 422', function (): void {
    [$owner, $business] = createBusinessOwner();

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/business/{$business->getKey()}", [
        'latitude' => 91,
        'longitude' => -181,
        'timezone' => null,
    ])->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude', 'timezone']);
    expect($business->refresh()->timezone)->toBe('UTC');
});

it('manages business closures and prevents deleting another business closure', function (): void {
    [$owner, $business] = createBusinessOwner();
    [$other] = createBusinessWithService();
    $date = now()->addDay()->toDateString();

    $closureId = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->getKey()}/closures", [
        'closures' => [['starts_on' => $date, 'ends_on' => $date, 'reason' => 'Maintenance']],
    ])->assertOk()->json('data.items.0.id');
    $this->assertDatabaseHas('business_closures', ['id' => $closureId, 'business_id' => $business->getKey()]);
    expect(app(AvailabilityService::class)->isClosureDate($business, now()->addDay()))->toBeTrue();

    $otherClosure = $other->closures()->create(['starts_on' => $date, 'ends_on' => $date]);
    $this->deleteJson("/api/v1/business/{$business->getKey()}/closures/{$otherClosure->getKey()}")->assertNotFound();
    $this->assertDatabaseHas('business_closures', ['id' => $otherClosure->getKey()]);

    $this->deleteJson("/api/v1/business/{$business->getKey()}/closures/{$closureId}")->assertOk();
    $this->assertDatabaseMissing('business_closures', ['id' => $closureId]);
});

it('removes branch creation and update endpoints', function (): void {
    [$owner, $business] = createBusinessOwner();
    $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->getKey()}/branches", ['branch_name' => 'Main'])->assertNotFound();
    $this->patchJson("/api/v1/business/{$business->getKey()}/branches/".Str::ulid(), ['address' => 'Street 12'])->assertNotFound();
});

it('returns current service prices with duration on create update list and detail', function (): void {
    [$owner, $business] = createBusinessOwner();

    $created = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->getKey()}/services", [
        'name' => 'Hot Stone Therapy',
        'duration' => '01:15',
        'price_minor' => 600000,
        'currency' => 'NPR',
    ])->assertCreated()->assertJsonCount(1, 'data.service_prices')
        ->assertJsonPath('data.service_prices.0.duration', '01:15:00')
        ->assertJsonPath('data.service_prices.0.price_minor', 600000);
    expect($created->json('data'))->not->toHaveKey('duration_minutes');
    $serviceId = $created->json('data.id');

    $updated = $this->patchJson("/api/v1/business/{$business->getKey()}/services/{$serviceId}", ['duration' => '01:30'])
        ->assertOk()->assertJsonCount(1, 'data.service_prices')
        ->assertJsonPath('data.service_prices.0.duration', '01:30:00')
        ->assertJsonPath('data.service_prices.0.price_minor', 600000);
    expect($updated->json('data'))->not->toHaveKey('duration_minutes');
    $this->assertDatabaseHas('service_prices', ['service_id' => $serviceId, 'duration' => '01:30:00', 'is_current' => true]);
    $this->assertDatabaseHas('service_prices', ['service_id' => $serviceId, 'duration' => '01:15:00', 'is_current' => false]);

    $list = $this->getJson("/api/v1/business/{$business->getKey()}/services")->assertOk();
    $listed = collect($list->json('data.items'))->firstWhere('id', $serviceId);
    expect($listed['service_prices'])->toHaveCount(1);
    expect($listed['service_prices'][0]['duration'])->toBe('01:30:00');
    expect($listed)->not->toHaveKey('duration_minutes');

    $detail = $this->getJson("/api/v1/businesses/{$business->getKey()}")->assertOk();
    $service = collect($detail->json('data.services'))->firstWhere('id', $serviceId);
    expect($service['service_prices'])->toHaveCount(1);
    expect($service['service_prices'][0]['duration'])->toBe('01:30:00');
    expect($service)->not->toHaveKey('duration_minutes');
});

it('rejects invalid service options and staff counts', function (string $field, mixed $value): void {
    [$owner, $business] = createBusinessOwner();

    $this->actingAs($owner, 'sanctum')->patchJson('/api/v1/business/'.$business->getKey(), [$field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect($business->refresh()->staff_number)->toBe(0)
        ->and($business->home_service)->toBeFalse()
        ->and($business->location_service)->toBeTrue();
})->with([
    'invalid home service' => ['home_service', 'yes'],
    'invalid location service' => ['location_service', 'yes'],
    'negative staff count' => ['staff_number', -1],
    'fractional staff count' => ['staff_number', 1.5],
    'overflowing staff count' => ['staff_number', 2147483648],
]);

it('hides inactive memberships and blocks their access to business profiles', function (): void {
    [$owner, $business] = createBusinessOwner();
    BusinessUser::query()->where('business_id', $business->id)->where('user_id', $owner->id)->update(['is_active' => false]);

    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/business/my')
        ->assertOk()->assertJsonCount(0, 'data.items');
    $this->getJson('/api/v1/business/'.$business->id)->assertForbidden();
    $this->patchJson('/api/v1/business/'.$business->id, ['name' => 'Unauthorized Change'])->assertForbidden();
    $this->assertDatabaseHas('businesses', ['id' => $business->id, 'name' => $business->name]);
});

it('preserves profile update permissions for managers and staff', function (string $role, int $expectedStatus): void {
    [, $business] = createBusinessOwner();
    [$member] = actingAsCustomer();
    BusinessUser::query()->create(['business_id' => $business->id, 'user_id' => $member->id, 'role' => $role, 'is_active' => true]);

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/business/'.$business->id)
        ->assertOk()->assertJsonMissingPath('data.kyc');
    $this->patchJson('/api/v1/business/'.$business->id, ['name' => 'Updated Wellness Spa'])->assertStatus($expectedStatus);
    $this->assertDatabaseHas('businesses', [
        'id' => $business->id,
        'name' => $expectedStatus === 200 ? 'Updated Wellness Spa' : $business->name,
    ]);
})->with([
    'manager can update' => ['manager', 200],
    'staff cannot update' => ['staff', 403],
]);

it('replaces the business image and individual KYC uploads before verification', function (bool $nested): void {
    Storage::fake('public');
    Storage::fake('local');
    [$owner, $business] = createBusinessOwner();
    $oldImage = 'business-images/'.$business->id.'/old.jpg';
    $oldDocument = 'business-kyc/'.$business->id.'/old.pdf';
    $retainedDocument = 'business-kyc/'.$business->id.'/identity.jpg';
    Storage::disk('public')->put($oldImage, 'old image');
    Storage::disk('local')->put($oldDocument, 'old document');
    Storage::disk('local')->put($retainedDocument, 'identity');
    $business->forceFill(['is_verified' => false])->save();
    $business->forceFill(['hero_image_path' => $oldImage, 'kyc_documents' => [
        'vat_pan_document' => $oldDocument, 'business_registration_document' => $retainedDocument,
    ]])->save();
    $document = UploadedFile::fake()->create('pan.pdf', 100, 'application/pdf');

    $response = $this->actingAs($owner, 'sanctum')->post('/api/v1/business/'.$business->id, [
        '_method' => 'PATCH', 'hero_image' => UploadedFile::fake()->image('spa.jpg'),
        ...($nested ? ['kyc_documents' => ['vat_pan_document' => $document]] : ['vat_pan_document' => $document]),
    ], ['Accept' => 'application/json'])->assertOk();

    $business->refresh();
    $response->assertJsonPath('data.hero_image_url', url('storage/'.$business->hero_image_path));
    Storage::disk('public')->assertExists($business->hero_image_path);
    Storage::disk('public')->assertMissing($oldImage);
    Storage::disk('local')->assertExists($business->kyc_documents['vat_pan_document']);
    Storage::disk('local')->assertMissing($oldDocument);
    Storage::disk('local')->assertExists($retainedDocument);
    expect($business->kyc_documents['business_registration_document'])->toBe($retainedDocument)
        ->and($business->is_verified)->toBeFalse();
})->with(['top level documents' => false, 'nested KYC documents' => true]);

it('rejects KYC changes after verification without updating the profile or storing files', function (bool $nested): void {
    Storage::fake('local');
    Storage::fake('public');
    [$owner, $business] = createBusinessOwner();
    $oldName = $business->name;
    $file = UploadedFile::fake()->create('pan.pdf', 100, 'application/pdf');

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/'.$business->id, [
        '_method' => 'PATCH', 'name' => 'Blocked Name', 'hero_image' => UploadedFile::fake()->image('spa.jpg'),
        ...($nested ? ['kyc_documents' => ['vat_pan_document' => $file]] : ['vat_pan_document' => $file]),
    ], ['Accept' => 'application/json'])->assertUnprocessable()
        ->assertJsonValidationErrors([$nested ? 'kyc_documents' : 'vat_pan_document']);

    expect($business->refresh()->name)->toBe($oldName)
        ->and($business->kyc_documents)->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['top level documents' => false, 'nested KYC documents' => true]);

it('allows image replacement and removal after verification', function (): void {
    Storage::fake('public');
    [$owner, $business] = createBusinessOwner();

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/'.$business->id, [
        '_method' => 'PATCH', 'hero_image' => UploadedFile::fake()->image('spa.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();
    $path = $business->refresh()->hero_image_path;
    Storage::disk('public')->assertExists($path);
    expect($business->is_verified)->toBeTrue();

    $this->patchJson('/api/v1/business/'.$business->id, ['hero_image_path' => null])
        ->assertOk()->assertJsonPath('data.hero_image_url', null);
    expect($business->refresh()->hero_image_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('rejects invalid image and KYC update uploads without changing the business', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    [$owner, $business] = createBusinessOwner();
    $business->forceFill(['is_verified' => false])->save();

    $this->actingAs($owner, 'sanctum')->post('/api/v1/business/'.$business->id, [
        '_method' => 'PATCH',
        'hero_image' => UploadedFile::fake()->create('spa.pdf', 100, 'application/pdf'),
        'kyc_documents' => ['vat_pan_document' => 'client-supplied/path.pdf'],
        'business_registration_document' => UploadedFile::fake()->image('identity.jpg')->size(5121),
    ], ['Accept' => 'application/json'])->assertUnprocessable()
        ->assertJsonValidationErrors(['hero_image', 'kyc_documents.vat_pan_document', 'business_registration_document']);

    expect($business->refresh()->hero_image_path)->toBeNull()
        ->and($business->kyc_documents)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBe([]);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});
