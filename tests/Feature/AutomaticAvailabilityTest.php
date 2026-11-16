<?php

use App\Models\Booking;
use App\Models\User;
use App\Services\BookingService;

it('generates complete service intervals inside staff and business hours excluding breaks leave and bookings', function (): void {
    [$business, $service, $membership, $url] = automaticAvailabilityFixture();
    $membership->leaves()->create(['starts_on' => '2026-11-14', 'ends_on' => '2026-11-14', 'starts_at' => '14:00', 'ends_at' => '14:30']);
    automaticBooking($business, $membership->user_id, '15:00', '16:00');

    $this->getJson($url)->assertOk()->assertJsonPath('data.dates.0.slots', [
        ['starts_at' => '10:00', 'ends_at' => '11:00'],
        ['starts_at' => '10:30', 'ends_at' => '11:30'],
        ['starts_at' => '11:00', 'ends_at' => '12:00'],
        ['starts_at' => '13:00', 'ends_at' => '14:00'],
    ]);
});

it('returns no slots without an active eligible scheduled staff member', function (string $reason): void {
    [$business, $service, $membership, $url] = automaticAvailabilityFixture();
    match ($reason) {
        'inactive membership' => $membership->update(['is_active' => false]),
        'inactive account' => $membership->user->update(['is_active' => false]),
        'deleted account' => $membership->user->delete(),
        'unassigned service' => $membership->services()->detach(),
        'missing schedule' => $membership->hours()->delete(),
        'day off' => $membership->hours()->update(['is_closed' => true]),
        'full day leave' => $membership->leaves()->create(['starts_on' => '2026-11-13', 'ends_on' => '2026-11-15']),
        'holiday' => $business->closures()->create(['starts_on' => '2026-11-14', 'ends_on' => '2026-11-15']),
        'organization closed' => $business->hours()->update(['is_closed' => true]),
        'organization too short' => $business->hours()->update(['opens_at' => '16:00', 'closes_at' => '17:00']),
    };

    $this->getJson($url)->assertOk()->assertJsonPath('data.dates.0.slots', []);
})->with(['inactive membership', 'inactive account', 'deleted account', 'unassigned service', 'missing schedule', 'day off', 'full day leave', 'holiday', 'organization closed', 'organization too short']);

it('approves parallel bookings only while different eligible staff remain available', function (): void {
    [$business, $service, $first] = automaticAvailabilityFixture();
    addBookingStaff($business, $service);
    $owner = bookingApprover($business);
    $requests = [];
    foreach (range(1, 3) as $index) {
        $customer = User::factory()->create();
        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings/requests', [
            'business_id' => $business->id, 'service_id' => $service->id,
            'requested_date' => '2026-11-14', 'requested_time' => '10:00',
        ])->assertAccepted()->assertJsonPath('data.status', 'pending');
        $requests[] = $response->json('data.booking_request_id');
    }
    foreach ($requests as $index => $requestId) {
        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$requestId.'/approve');
        if ($index < 2) {
            $response->assertCreated();
        } else {
            $response->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT');
        }
    }
    $this->assertDatabaseCount('bookings', 2);
    expect(Booking::query()->whereNotNull('assigned_staff_user_id')->count())->toBe(0);
    $this->assertDatabaseCount('booking_staff', 0);
});

it('preserves the customer selected time when schedules change instead of generating replacement proposals', function (): void {
    [$business, $service, $membership] = automaticAvailabilityFixture();
    $customer = User::factory()->create();
    $request = app(BookingService::class)->createRequest($customer, ['business_id' => $business->id,
        'service_id' => $service->id, 'requested_date' => '2026-11-14', 'requested_time' => '10:00']);
    $membership->hours()->update(['opens_at' => '11:00']);

    $this->actingAs($customer, 'sanctum')->getJson('/api/v1/bookings/requests/'.$request->id)->assertOk()
        ->assertJsonPath('data.requested_time', '10:00')->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.available_times');
    $this->assertDatabaseCount('booking_request_times', 0);
});

it('rechecks staff changes before confirming an earlier generated option', function (string $change): void {
    [$business, $service, $membership] = automaticAvailabilityFixture();
    $customer = User::factory()->create();
    $request = app(BookingService::class)->createRequest($customer, ['business_id' => $business->id, 'service_id' => $service->id, 'requested_time' => '10:00', 'requested_date' => '2026-11-14']);
    match ($change) {
        'leave' => $membership->leaves()->create(['starts_on' => '2026-11-14', 'ends_on' => '2026-11-14']),
        'break' => $membership->hours->each->update(['breaks' => [['starts_at' => '10:30', 'ends_at' => '11:00']]]),
        'hours' => $membership->hours()->update(['opens_at' => '11:00']),
        'assignment' => $membership->services()->detach(),
        'deactivation' => $membership->update(['is_active' => false]),
    };

    $this->actingAs(bookingApprover($business), 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve')
        ->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT');

    $this->assertDatabaseCount('bookings', 0);
    $this->assertDatabaseCount('booking_items', 0);
})->with(['leave', 'break', 'hours', 'assignment', 'deactivation']);

it('blocks staff bookings at another business using each bookings timezone', function (): void {
    [$business, $service, $membership, $url] = automaticAvailabilityFixture();
    [$other] = createBusinessWithService();
    $other->update(['timezone' => 'Asia/Kathmandu']);
    automaticBooking($other, $membership->user_id, '15:45', '16:45');

    $response = $this->getJson($url)->assertOk();
    expect(collect($response->json('data.dates.0.slots'))->pluck('starts_at')->all())->not->toContain('10:00', '10:30')->toContain('11:00');
});

it('stores the selected interval in the business timezone for approval', function (): void {
    [$business, $service] = automaticAvailabilityFixture();
    $business->update(['timezone' => 'Asia/Kathmandu']);
    $customer = User::factory()->create();
    $request = app(BookingService::class)->createRequest($customer, ['business_id' => $business->id, 'service_id' => $service->id,
        'requested_date' => '2026-11-14', 'requested_time' => '10:00']);
    app(BookingService::class)->approveRequest(bookingApprover($business), $request);
    $this->assertDatabaseHas('bookings', ['starts_at' => '2026-11-14 10:00:00', 'ends_at' => '2026-11-14 11:00:00', 'timezone' => 'Asia/Kathmandu']);
});

it('requires the configured number of qualified staff to be available for the complete service', function (): void {
    [$business, $service, $first, $url] = automaticAvailabilityFixture();
    $service->update(['required_staff_count' => 2]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.service.required_staff_count', 2)->assertJsonPath('data.dates.0.slots', []);
    $second = addBookingStaff($business, $service);
    $this->getJson($url)->assertOk()->assertJsonPath('data.dates.0.slots.0.starts_at', '10:00');
    $second->leaves()->create(['starts_on' => '2026-11-14', 'ends_on' => '2026-11-14', 'starts_at' => '10:30', 'ends_at' => '11:00']);

    $response = $this->getJson($url)->assertOk();
    expect(collect($response->json('data.dates.0.slots'))->pluck('starts_at')->all())->not->toContain('10:00', '10:30')->toContain('11:00');
});

it('reuses staff capacity across adjacent unassigned bookings', function (): void {
    [$business, $service, $first, $url] = automaticAvailabilityFixture();
    addBookingStaff($business, $service);
    $first->hours->each->update(['breaks' => []]);
    $customer = User::factory()->create();
    foreach (['10:00', '11:00'] as $start) {
        $request = app(BookingService::class)->createRequest($customer, ['business_id' => $business->id, 'service_id' => $service->id, 'requested_time' => $start, 'requested_date' => '2026-11-14']);
        app(BookingService::class)->approveRequest(bookingApprover($business), $request);
    }

    $response = $this->getJson($url)->assertOk();
    expect(collect($response->json('data.dates.0.slots'))->pluck('starts_at')->all())->toContain('10:30');
    $this->assertDatabaseCount('bookings', 2);
    $this->assertDatabaseCount('booking_staff', 0);
});

it('displays availability in the requested timezone using the actual business and staff working hours', function (): void {
    [$business, , , $url] = automaticAvailabilityFixture();
    $business->update(['timezone' => 'Asia/Kathmandu']);

    $this->getJson($url.'&timezone=UTC')->assertOk()->assertJsonPath('data.timezone', 'UTC')
        ->assertJsonPath('data.dates.0.slots.0', ['starts_at' => '04:15', 'ends_at' => '05:15']);
});

it('excludes every staff break and allows appointments touching break boundaries', function (): void {
    [$business, $service, $membership, $url] = automaticAvailabilityFixture();
    $membership->hours->each->update(['breaks' => [
        ['starts_at' => '11:00', 'ends_at' => '11:30'],
        ['starts_at' => '14:00', 'ends_at' => '14:30'],
    ]]);

    $this->getJson($url)->assertOk()->assertJsonPath('data.dates.0.slots', [
        ['starts_at' => '10:00', 'ends_at' => '11:00'],
        ['starts_at' => '11:30', 'ends_at' => '12:30'],
        ['starts_at' => '12:00', 'ends_at' => '13:00'],
        ['starts_at' => '12:30', 'ends_at' => '13:30'],
        ['starts_at' => '13:00', 'ends_at' => '14:00'],
        ['starts_at' => '14:30', 'ends_at' => '15:30'],
        ['starts_at' => '15:00', 'ends_at' => '16:00'],
    ]);
});
