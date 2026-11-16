<?php

use App\Models\BusinessUser;
use App\Models\Offer;
use App\Models\ServicePrice;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
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

function createBookingBusinessOwner(): array
{
    [$owner, $business] = createBusinessOwner();
    addBookingStaff($business, $business->services()->firstOrFail());

    return [$owner, $business];
}

it('persists the business rejection reason and returns it to the business and customer', function (?string $reason): void {
    $this->freezeTime();
    [$owner, $business] = createBookingBusinessOwner();
    $customer = User::factory()->create();
    $service = $business->services()->firstOrFail();
    $bookingRequest = app(BookingService::class)->createRequest($customer, [
        'business_id' => $business->getKey(),
        'service_id' => $service->getKey(),
        'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
    ]);
    $url = '/api/v1/business/'.$business->getKey().'/bookings/requests';

    $this->postJson($url.'/'.$bookingRequest->getKey().'/reject', ['reason' => $reason])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', $reason);

    $this->assertDatabaseHas('booking_requests', [
        'id' => $bookingRequest->getKey(),
        'status' => 'rejected',
        'decline_reason' => $reason,
    ]);
    $this->assertDatabaseHas('notifications', [
        'user_id' => $customer->getKey(),
        'title' => 'Booking request rejected',
        'body' => $business->name.' cannot serve your request.'.($reason !== null ? ' Reason: '.$reason : ''),
    ]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.items.0.rejection_reason', $reason);
    $this->actingAs($customer, 'sanctum')
        ->getJson('/api/v1/bookings/requests/'.$bookingRequest->getKey())
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', $reason);
})->with([
    'with reason' => ['No appointments available on the requested date.'],
    'without reason' => [null],
]);

it('lists only the price selected for each booking request even when requests share a service', function (): void {
    [$owner, $business] = createBookingBusinessOwner();
    $service = $business->services()->firstOrFail();
    $firstPrice = $service->prices()->firstOrFail();
    $secondPrice = $service->prices()->create(['price_minor' => 750000, 'currency' => 'NPR', 'duration' => '01:30', 'is_current' => true]);
    $expectedPrices = [];
    foreach ([$firstPrice, $secondPrice] as $price) {
        $bookingRequest = app(BookingService::class)->createRequest($owner, [
            'business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $price->id,
            'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
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
    [$owner, $business] = createBookingBusinessOwner();
    $service = $business->services()->firstOrFail();
    $bookingRequest = app(BookingService::class)->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id,
        'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
    ]);
    $bookingRequest->update(['service_price_id' => null]);

    $this->getJson('/api/v1/business/'.$business->id.'/bookings/requests')->assertOk()
        ->assertJsonCount(0, 'data.items.0.service.service_prices');
});

it('returns only the selected price when declining a booking request', function (): void {
    [$owner, $business] = createBookingBusinessOwner();
    $service = $business->services()->firstOrFail();
    $price = $service->prices()->create(['price_minor' => 750000, 'currency' => 'NPR', 'duration' => '01:30', 'is_current' => true]);
    $date = now()->addDay()->toDateString();
    $bookingRequest = app(BookingService::class)->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $price->id, 'requested_time' => '10:00', 'requested_date' => $date,
    ]);

    $this->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$bookingRequest->id.'/reject', [
        'starts_at' => ['10:00'],
    ])->assertOk()->assertJsonCount(1, 'data.service.service_prices')
        ->assertJsonPath('data.service.service_prices.0.id', $price->id);
});

it('uses the selected service duration when approving the requested start time', function (): void {
    $this->travelTo(Carbon::parse('2026-10-29 08:00', 'UTC'));
    [$owner, $business] = createBookingBusinessOwner();
    $service = $business->services()->firstOrFail();
    $price = $service->prices()->create(['duration' => '01:30', 'price_minor' => 750000, 'currency' => 'NPR', 'is_current' => true]);
    $request = app(BookingService::class)->createRequest($owner, ['business_id' => $business->id, 'service_id' => $service->id,
        'service_price_id' => $price->id, 'requested_date' => '2026-10-30', 'requested_time' => '10:00']);
    app(BookingService::class)->approveRequest($owner, $request);

    $this->assertDatabaseHas('bookings', ['booking_request_id' => $request->id,
        'starts_at' => '2026-10-30 10:00:00', 'ends_at' => '2026-10-30 11:30:00']);
    $this->assertDatabaseCount('booking_request_times', 0);
});

it('calculates the approved service interval across daylight saving in the business timezone', function (): void {
    $this->travelTo(Carbon::parse('2027-03-13 08:00', 'America/New_York'));
    [$owner, $business] = createBookingBusinessOwner();
    $business->update(['timezone' => 'America/New_York']);
    $business->hours()->update(['opens_at' => '00:00', 'closes_at' => '05:00']);
    $service = $business->services()->firstOrFail();
    $service->staffMemberships()->firstOrFail()->hours()->update(['opens_at' => '00:00', 'closes_at' => '23:59']);
    $price = $service->prices()->firstOrFail();
    $price->update(['duration' => '01:30']);
    $request = app(BookingService::class)->createRequest($owner, ['business_id' => $business->id,
        'service_id' => $service->id, 'service_price_id' => $price->id,
        'requested_date' => '2027-03-14', 'requested_time' => '01:00']);
    app(BookingService::class)->approveRequest($owner, $request);

    $this->assertDatabaseHas('bookings', ['booking_request_id' => $request->id,
        'starts_at' => '2027-03-14 01:00:00', 'ends_at' => '2027-03-14 03:30:00']);
});

it('returns 404 for manual proposal payloads without changing generated options or notifying the customer', function (array $payload): void {
    $this->freezeTime();
    [$owner, $business] = createBookingBusinessOwner();
    $service = $business->services()->firstOrFail();
    $bookingRequest = app(BookingService::class)->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id, 'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
    ]);
    $before = $bookingRequest->requested_time->format('H:i');
    $notifications = $owner->notifications()->count();

    $this->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$bookingRequest->id.'/propose', $payload)->assertNotFound();

    expect($bookingRequest->refresh()->status->value)->toBe('pending');
    expect($bookingRequest->requested_time->format('H:i'))->toBe($before);
    expect($owner->notifications()->count())->toBe($notifications);
})->with([
    'missing times' => [[]], 'empty times' => [['starts_at' => []]],
    'full datetime' => [['starts_at' => ['2026-10-31T10:00:00+05:45']]],
    'seconds' => [['starts_at' => ['10:00:00']]], 'invalid hour' => [['starts_at' => ['24:00']]],
    'invalid minute' => [['starts_at' => ['10:60']]], 'missing leading zero' => [['starts_at' => ['9:00']]],
    'outside opening hours' => [['starts_at' => ['10:00', '18:00']]], 'ends after closing' => [['starts_at' => ['10:00', '16:30']]],
]);

it('returns 404 for duplicate manual proposals without inserting times', function (): void {
    $this->freezeTime();
    [$owner, $business] = createBookingBusinessOwner();
    $service = $business->services()->firstOrFail();
    $bookingRequest = app(BookingService::class)->createRequest($owner, [
        'business_id' => $business->id, 'service_id' => $service->id, 'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
    ]);

    $this->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$bookingRequest->id.'/propose', [
        'starts_at' => ['10:00', '10:00'],
    ])->assertNotFound();

    $this->assertDatabaseCount('booking_request_times', 0);
});

it('returns 404 for manual proposals without changing the selected request time', function (): void {
    $this->freezeTime();
    [$owner, $business] = createBookingBusinessOwner();
    $service = $business->services()->firstOrFail();
    $request = app(BookingService::class)->createRequest($owner, ['business_id' => $business->id,
        'service_id' => $service->id, 'requested_date' => now()->addDay()->toDateString(), 'requested_time' => '10:00']);

    $this->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/propose', ['starts_at' => ['14:00', '10:00']])->assertNotFound();
    expect($request->refresh()->requested_time->format('H:i'))->toBe('10:00');
    $this->assertDatabaseCount('booking_request_times', 0);
});

it('keeps the selected time stable when reading a pending request repeatedly', function (): void {
    $this->freezeTime();
    [$owner, $business] = createBookingBusinessOwner();
    $service = $business->services()->firstOrFail();
    $request = app(BookingService::class)->createRequest($owner, ['business_id' => $business->id,
        'service_id' => $service->id, 'requested_date' => now()->addDay()->toDateString(), 'requested_time' => '10:00']);
    $notifications = $owner->notifications()->count();

    foreach (range(1, 2) as $attempt) {
        $this->getJson('/api/v1/bookings/requests/'.$request->id)->assertOk()->assertJsonPath('data.requested_time', '10:00')
            ->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.available_times');
    }
    expect($owner->notifications()->count())->toBe($notifications);
    $this->assertDatabaseCount('booking_request_times', 0);
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

it('toggles business online state without a request body', function (string $role, bool $initialState, bool $expectedState, string $expectedMessage): void {
    [$owner, $business] = createBusinessOwner();
    $business->update(['is_online' => $initialState]);
    BusinessUser::query()->where('business_id', $business->getKey())
        ->where('user_id', $owner->getKey())->update(['role' => $role]);

    $this->actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}/toggleStatus")
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => $expectedMessage,
            'data' => null,
        ]);

    $this->assertDatabaseHas('businesses', [
        'id' => $business->getKey(),
        'is_online' => $expectedState,
    ]);
})->with([
    'owner goes offline' => ['owner', true, false, 'Business is offline.'],
    'owner goes online' => ['owner', false, true, 'Business is online.'],
    'manager goes offline' => ['manager', true, false, 'Business is offline.'],
    'manager goes online' => ['manager', false, true, 'Business is online.'],
]);

it('ignores profile fields when toggling business online state', function (): void {
    [$owner, $business] = createBusinessOwner();

    $this->actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}/toggleStatus", [
            'is_online' => true,
            'name' => 'Unintended profile update',
        ])
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Business is offline.',
            'data' => null,
        ]);

    $this->assertDatabaseHas('businesses', [
        'id' => $business->getKey(),
        'is_online' => false,
        'name' => $business->name,
    ]);
});

it('returns 401 when toggling business online state without authentication', function (): void {
    [$business] = createBusinessWithService();

    $this->patchJson("/api/v1/business/{$business->getKey()}/toggleStatus")
        ->assertUnauthorized();

    $this->assertDatabaseHas('businesses', ['id' => $business->getKey(), 'is_online' => true]);
});

it('returns 403 when a non-member toggles business online state', function (): void {
    [$owner, $business] = createBusinessOwner();
    $outsider = User::factory()->create();

    $this->actingAs($outsider, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}/toggleStatus")
        ->assertForbidden();

    $this->assertDatabaseHas('businesses', ['id' => $business->getKey(), 'is_online' => true]);
});

it('returns 403 when staff toggles business online state', function (): void {
    [$owner, $business] = createBusinessOwner();
    BusinessUser::query()->where('business_id', $business->getKey())
        ->where('user_id', $owner->getKey())->update(['role' => 'staff']);

    $this->actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}/toggleStatus")
        ->assertForbidden();

    $this->assertDatabaseHas('businesses', ['id' => $business->getKey(), 'is_online' => true]);
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

it('filters business staff by active membership status', function (?string $status): void {
    [$owner, $business] = createBusinessOwner();
    $activeStaff = User::factory()->create(['is_active' => false]);
    $inactiveStaff = User::factory()->create(['is_active' => true]);
    foreach ([[$activeStaff, true], [$inactiveStaff, false]] as [$staff, $isActive]) {
        BusinessUser::query()->create([
            'business_id' => $business->getKey(),
            'user_id' => $staff->getKey(),
            'role' => 'staff',
            'is_active' => $isActive,
        ]);
    }
    [$otherBusiness] = createBusinessWithService();
    BusinessUser::query()->create([
        'business_id' => $otherBusiness->getKey(),
        'user_id' => $inactiveStaff->getKey(),
        'role' => 'staff',
        'is_active' => true,
    ]);
    $expectedIds = match ($status) {
        'active' => [$owner->getKey(), $activeStaff->getKey()],
        'inactive' => [$inactiveStaff->getKey()],
        default => [$owner->getKey(), $activeStaff->getKey(), $inactiveStaff->getKey()],
    };
    $url = '/api/v1/business/'.$business->getKey().'/staff'.($status !== null ? '?status='.$status : '');

    $this->getJson($url)
        ->assertOk()
        ->assertJsonCount(count($expectedIds), 'data.items')
        ->assertJsonPathCanonicalizing('data.items.*.id', $expectedIds);
})->with(['all' => [null], 'active' => ['active'], 'inactive' => ['inactive']]);

it('returns 422 for an invalid staff status filter', function (): void {
    [, $business] = createBusinessOwner();

    $this->getJson('/api/v1/business/'.$business->getKey().'/staff?status=unknown')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('filters business staff by role alone or together with membership status', function (string $query, array $expectedMembers): void {
    [$owner, $business] = createBusinessOwner();
    $members = ['owner' => $owner];
    foreach ([['manager', 'manager', true], ['active_staff', 'staff', true], ['inactive_staff', 'staff', false]] as [$name, $role, $active]) {
        $members[$name] = User::factory()->create();
        BusinessUser::query()->create([
            'business_id' => $business->getKey(),
            'user_id' => $members[$name]->getKey(),
            'role' => $role,
            'is_active' => $active,
        ]);
    }
    [$otherBusiness] = createBusinessWithService();
    BusinessUser::query()->create([
        'business_id' => $otherBusiness->getKey(),
        'user_id' => $owner->getKey(),
        'role' => 'staff',
        'is_active' => true,
    ]);
    $expectedIds = array_map(fn (string $name): string => $members[$name]->getKey(), $expectedMembers);

    $this->getJson('/api/v1/business/'.$business->getKey().'/staff?'.$query)
        ->assertOk()
        ->assertJsonCount(count($expectedIds), 'data.items')
        ->assertJsonPathCanonicalizing('data.items.*.id', $expectedIds);
})->with([
    'owners' => ['role=owner', ['owner']],
    'managers' => ['role=manager', ['manager']],
    'staff' => ['role=staff', ['active_staff', 'inactive_staff']],
    'active staff' => ['role=staff&status=active', ['active_staff']],
    'inactive staff' => ['role=staff&status=inactive', ['inactive_staff']],
    'no matching members' => ['role=manager&status=inactive', []],
]);

it('returns 422 for an invalid staff role filter', function (): void {
    [, $business] = createBusinessOwner();

    $this->getJson('/api/v1/business/'.$business->getKey().'/staff?role=superadmin')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});

it('uses the optional invitation password only when creating a new user', function (array $passwordPayload, string $expectedPassword): void {
    [, $business] = createBusinessOwner();
    Str::createRandomStringsUsing(fn (int $length): string => str_repeat('x', $length));

    try {
        $response = $this->postJson('/api/v1/business/'.$business->getKey().'/staff/invite', [
            'mobile_number' => '+9779800000001',
            'display_name' => 'New Staff',
            'role' => 'staff',
            ...$passwordPayload,
        ])->assertCreated()->assertJsonMissingPath('data.password');
    } finally {
        Str::createRandomStringsNormally();
    }

    $user = User::query()->findOrFail($response->json('data.id'));
    expect(Hash::check($expectedPassword, $user->password))->toBeTrue();
    $this->assertDatabaseHas('business_users', [
        'business_id' => $business->getKey(), 'user_id' => $user->getKey(), 'role' => 'staff', 'is_active' => true,
    ]);
})->with([
    'provided password' => [['password' => 'StaffPass#123'], 'StaffPass#123'],
    'null password' => [['password' => null], str_repeat('x', 24)],
    'omitted password' => [[], str_repeat('x', 24)],
]);

it('creates invited staff with an optional job title image and password', function (): void {
    Storage::fake('public');
    [, $business] = createBusinessOwner();

    $response = $this->post('/api/v1/business/'.$business->getKey().'/staff/invite', [
        'mobile_number' => '9841234567',
        'display_name' => 'Anita',
        'job_title' => 'Massage Therapist',
        'image' => UploadedFile::fake()->image('portrait.jpg'),
        'password' => 'StaffPass#123',
        'role' => 'staff',
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.job_title', 'Massage Therapist')
        ->assertJsonMissingPath('data.password');

    $user = User::query()->findOrFail($response->json('data.id'));
    Storage::disk('public')->assertExists($user->image);
    expect(Hash::check('StaffPass#123', $user->password))->toBeTrue();
    $this->assertDatabaseHas('users', ['id' => $user->getKey(), 'job_title' => 'Massage Therapist']);
    $response->assertJsonPath('data.avatar_url', url('storage/'.$user->image));
    $this->getJson('/api/v1/business/'.$business->getKey().'/staff?role=staff')
        ->assertOk()->assertJsonPath('data.items.0.job_title', 'Massage Therapist')
        ->assertJsonPath('data.items.0.avatar_url', url('storage/'.$user->image));
});

it('accepts null optional staff profile fields', function (): void {
    [, $business] = createBusinessOwner();

    $this->postJson('/api/v1/business/'.$business->getKey().'/staff/invite', [
        'mobile_number' => '9841234567', 'role' => 'staff',
        'job_title' => null, 'image' => null, 'password' => null,
    ])->assertCreated()->assertJsonPath('data.job_title', null)->assertJsonPath('data.avatar_url', null);
});

it('rejects invalid staff profile fields without creating a user or uploading an image', function (string $kind, string $field): void {
    Storage::fake('public');
    [, $business] = createBusinessOwner();
    $invalidValue = match ($kind) {
        'long title' => str_repeat('a', 81),
        'non-image' => UploadedFile::fake()->create('file.pdf', 10, 'application/pdf'),
        'large image' => UploadedFile::fake()->image('portrait.jpg')->size(5121),
    };

    $this->post('/api/v1/business/'.$business->getKey().'/staff/invite', [
        'mobile_number' => '9841234567', 'role' => 'staff', $field => $invalidValue,
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseMissing('users', ['mobile' => '+9779841234567']);
    Storage::disk('public')->assertDirectoryEmpty('avatars');
})->with([
    ['long title', 'job_title'], ['non-image', 'image'], ['large image', 'image'],
]);

it('removes an uploaded staff image when user creation fails', function (): void {
    Storage::fake('public');
    [, $business] = createBusinessOwner();
    Hash::shouldReceive('make')->once()->andThrow(new RuntimeException('User creation failed.'));

    $this->post('/api/v1/business/'.$business->getKey().'/staff/invite', [
        'mobile_number' => '9841234567', 'role' => 'staff',
        'image' => UploadedFile::fake()->image('portrait.jpg'),
    ], ['Accept' => 'application/json'])->assertServerError();

    $this->assertDatabaseMissing('users', ['mobile' => '+9779841234567']);
    Storage::disk('public')->assertDirectoryEmpty('avatars');
});

it('preserves the existing user password and profile when invited with a password', function (): void {
    Storage::fake('public');
    [, $business] = createBusinessOwner();
    $user = User::factory()->create(['display_name' => 'Original Name', 'job_title' => 'Original Title', 'image' => 'avatars/original.jpg']);
    $originalHash = $user->password;

    $this->post('/api/v1/business/'.$business->getKey().'/staff/invite', [
        'mobile_number' => $user->mobile,
        'display_name' => 'Replacement Name',
        'password' => 'Replacement#123',
        'job_title' => 'Replacement Title',
        'image' => UploadedFile::fake()->image('replacement.jpg'),
        'role' => 'staff',
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.id', $user->getKey());

    expect($user->refresh()->password)->toBe($originalHash)
        ->and($user->display_name)->toBe('Original Name')
        ->and($user->job_title)->toBe('Original Title')
        ->and($user->image)->toBe('avatars/original.jpg');
    Storage::disk('public')->assertDirectoryEmpty('avatars');
    $this->assertDatabaseHas('business_users', [
        'business_id' => $business->getKey(), 'user_id' => $user->getKey(), 'role' => 'staff', 'is_active' => true,
    ]);
});

it('rejects an invalid invitation password without creating a user', function (mixed $password): void {
    [, $business] = createBusinessOwner();

    $this->postJson('/api/v1/business/'.$business->getKey().'/staff/invite', [
        'mobile_number' => '+9779800000001',
        'role' => 'staff',
        'password' => $password,
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->assertDatabaseMissing('users', ['mobile' => '+9779800000001']);
})->with(['too short' => ['Ab1!'], 'missing complexity' => ['abcdefgh'], 'invalid type' => [12345678]]);

it('normalizes Nepal mobile numbers before looking up an existing staff user', function (string $mobile): void {
    [, $business] = createBusinessOwner();
    $user = User::factory()->create(['mobile' => '+9779841234567']);

    $this->postJson('/api/v1/business/'.$business->getKey().'/staff/invite', [
        'mobile_number' => $mobile,
        'role' => 'staff',
    ])->assertCreated()->assertJsonPath('data.id', $user->getKey());

    $this->assertDatabaseCount('users', 2);
})->with(['9841234567', '+9779841234567', '+977 (984) 123-4567']);

it('accepts Nepal mobile prefixes when inviting new staff', function (string $mobile): void {
    [, $business] = createBusinessOwner();

    $this->postJson('/api/v1/business/'.$business->getKey().'/staff/invite', [
        'mobile_number' => $mobile,
        'role' => 'staff',
    ])->assertCreated();

    $this->assertDatabaseHas('users', ['mobile' => '+977'.$mobile]);
})->with(['9611234567', '9711234567', '9841234567']);

it('returns a clear 422 for invalid Nepal mobile numbers without inviting staff', function (mixed $mobile): void {
    [, $business] = createBusinessOwner();

    $this->postJson('/api/v1/business/'.$business->getKey().'/staff/invite', [
        'mobile_number' => $mobile,
        'role' => 'staff',
    ])->assertUnprocessable()->assertJsonValidationErrors('mobile_number');

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('business_users', 1);
})->with([
    'foreign number' => ['+919841234567'],
    'landline' => ['+97714234567'],
    'invalid prefix' => ['+9779541234567'],
    'too short' => ['+977984123456'],
    'too long' => ['+97798412345678'],
    'letters' => ['984123456a'],
    'array' => [['9841234567']],
    'numeric input' => [9841234567],
]);

it('invites staff by mobile number and deactivates them', function (): void {
    [$owner, $business] = createBusinessOwner();

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/v1/business/{$business->getKey()}/staff/invite", [
            'mobile_number' => '+97798'.random_int(10000000, 99999999),
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
        ->assertJsonPath('data.service_prices.0.duration', '01:15')
        ->assertJsonPath('data.service_prices.0.price_minor', 600000);
    expect($created->json('data'))->not->toHaveKey('duration_minutes');
    $serviceId = $created->json('data.id');

    $updated = $this->patchJson("/api/v1/business/{$business->getKey()}/services/{$serviceId}", ['duration' => '01:30'])
        ->assertOk()->assertJsonCount(1, 'data.service_prices')
        ->assertJsonPath('data.service_prices.0.duration', '01:30')
        ->assertJsonPath('data.service_prices.0.price_minor', 600000);
    expect($updated->json('data'))->not->toHaveKey('duration_minutes');
    $this->assertDatabaseHas('service_prices', ['service_id' => $serviceId, 'duration' => '01:30:00', 'is_current' => true]);
    $this->assertDatabaseHas('service_prices', ['service_id' => $serviceId, 'duration' => '01:15:00', 'is_current' => false]);

    $list = $this->getJson("/api/v1/business/{$business->getKey()}/services")->assertOk();
    $listed = collect($list->json('data.items'))->firstWhere('id', $serviceId);
    expect($listed['service_prices'])->toHaveCount(1);
    expect($listed['service_prices'][0]['duration'])->toBe('01:30');
    expect($listed)->not->toHaveKey('duration_minutes');

    $detail = $this->getJson("/api/v1/businesses/{$business->getKey()}")->assertOk();
    $service = collect($detail->json('data.services'))->firstWhere('id', $serviceId);
    expect($service['service_prices'])->toHaveCount(1);
    expect($service['service_prices'][0]['duration'])->toBe('01:30');
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

it('creates and updates service staffing requirements independently of customer capacity', function (): void {
    [$owner, $business] = createBusinessOwner();
    $url = '/api/v1/business/'.$business->id.'/services';
    $response = $this->postJson($url, ['name' => 'Couples treatment', 'duration' => '01:00', 'price_minor' => 500000, 'max_people' => 2, 'required_staff_count' => 3])
        ->assertCreated()->assertJsonPath('data.required_staff_count', 3)->assertJsonPath('data.max_people', 2);
    $id = $response->json('data.id');
    $this->assertDatabaseHas('services', ['id' => $id, 'required_staff_count' => 3]);

    $this->patchJson($url.'/'.$id, ['required_staff_count' => 2])->assertOk()->assertJsonPath('data.required_staff_count', 2);
    $this->getJson($url.'/'.$id)->assertOk()->assertJsonPath('data.required_staff_count', 2);
    $this->assertDatabaseHas('services', ['id' => $id, 'required_staff_count' => 2, 'max_people' => 2]);
    $this->postJson($url, ['name' => 'Default staff treatment', 'duration' => '01:00', 'price_minor' => 500000])->assertCreated()->assertJsonPath('data.required_staff_count', 1);
});

it('returns 422 for invalid service staff requirements without updating the service', function (mixed $count): void {
    [$owner, $business] = createBusinessOwner();
    $service = $business->services()->firstOrFail();

    $this->patchJson('/api/v1/business/'.$business->id.'/services/'.$service->id, ['required_staff_count' => $count])
        ->assertUnprocessable()->assertJsonValidationErrors('required_staff_count');

    $this->assertDatabaseHas('services', ['id' => $service->id, 'required_staff_count' => 1]);
})->with([0, -1, 51, 1.5, 'two', null]);
