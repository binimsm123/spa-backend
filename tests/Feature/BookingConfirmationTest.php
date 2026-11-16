<?php

use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Support\Carbon;

/** @return array{User, Business, Service, BookingRequest} */
function createProposedConfirmationRequest(string $businessTimezone = 'Asia/Kathmandu', ?string $requestTimezone = null): array
{
    test()->travelTo(Carbon::parse('2026-11-03 14:56:00', 'Asia/Kathmandu'));
    $customer = User::factory()->create();
    [$business, $service, $price] = createBookableBusinessWithService();
    $business->update(['timezone' => $businessTimezone]);
    $business->hours()->update(['opens_at' => '09:30', 'closes_at' => '20:00']);
    BusinessUser::query()->create(['business_id' => $business->id, 'user_id' => $customer->id, 'role' => 'owner', 'is_active' => true]);
    $price->update(['duration' => '01:30']);
    $bookings = app(BookingService::class);
    $request = $bookings->createRequest($customer, [
        'business_id' => $business->id,
        'service_id' => $service->id,
        'service_price_id' => $price->id,
        'requested_time' => '16:30', 'requested_date' => '2026-11-03',
        'people_count' => 2,
        'timezone' => $requestTimezone ?? $businessTimezone,
    ]);

    return [$customer, $business, $service, $request];
}

function createConfirmationBlockingBooking(User $customer, Business $business, string $startsAt, string $endsAt, string $status = 'confirmed'): Booking
{
    return Booking::query()->create([
        'user_id' => $customer->id,
        'business_id' => $business->id,
        'appointment_date' => '2026-11-03',
        'starts_at' => '2026-11-03 '.$startsAt,
        'ends_at' => '2026-11-03 '.$endsAt,
        'timezone' => $business->timezone,
        'status' => $status,
        'subtotal_minor' => 500000,
        'total_minor' => 500000,
        'currency' => 'NPR',
    ]);
}

it('approves the selected request time with the selected service duration', function (): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertCreated()->assertJsonPath('data.status', 'awaiting_payment');

    $this->assertDatabaseHas('bookings', [
        'booking_request_id' => $request->id,
        'appointment_date' => '2026-11-03',
        'starts_at' => '2026-11-03 16:30:00',
        'ends_at' => '2026-11-03 18:00:00',
        'timezone' => 'Asia/Kathmandu',
        'people_count' => 2,
        'total_minor' => 1000000,
    ]);
    $this->assertDatabaseHas('booking_requests', ['id' => $request->id, 'status' => 'approved']);
    $this->assertDatabaseHas('booking_items', ['duration_minutes_snapshot' => 90, 'quantity' => 2]);
    expect($customer->notifications()->where('title', 'Booking awaiting payment')->count())->toBe(1);
});

it('returns 409 for a proposed time that has started without creating a booking', function (string $currentTime): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest();
    $this->travelTo(Carbon::parse('2026-11-03 '.$currentTime, 'Asia/Kathmandu'));

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT')
        ->assertJsonPath('message', 'The selected time has already started. Pick another time.');

    $this->assertDatabaseCount('bookings', 0);
    $this->assertDatabaseHas('booking_requests', ['id' => $request->id, 'status' => 'pending']);
    $this->assertDatabaseMissing('notifications', ['user_id' => $customer->id, 'title' => 'Booking awaiting payment']);
})->with(['exact start' => '16:30:00', 'already started' => '16:31:00']);

it('returns 409 for an interval overlapping an existing booking', function (string $startsAt, string $endsAt): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest();
    createConfirmationBlockingBooking($customer, $business, $startsAt, $endsAt);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT')
        ->assertJsonPath('message', 'There are not enough available staff for the selected time. Pick another time.');

    $this->assertDatabaseCount('bookings', 1);
    $this->assertDatabaseCount('booking_items', 0);
    $this->assertDatabaseHas('booking_requests', ['id' => $request->id, 'status' => 'pending']);
    $this->assertDatabaseMissing('notifications', ['user_id' => $customer->id, 'title' => 'Booking awaiting payment']);
})->with([
    'overlapping start' => ['16:00:00', '17:00:00'],
    'overlapping end' => ['17:30:00', '18:30:00'],
    'enclosing booking' => ['16:00:00', '19:00:00'],
    'contained booking' => ['17:00:00', '17:30:00'],
    'same interval' => ['16:30:00', '18:00:00'],
]);

it('treats active booking statuses as blocking confirmation', function (string $status): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest();
    createConfirmationBlockingBooking($customer, $business, '16:30:00', '18:00:00', $status);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT');

    $this->assertDatabaseCount('bookings', 1);
})->with(['awaiting_payment', 'in_progress', 'completed']);

it('allows adjacent bookings without treating their boundaries as overlapping', function (string $startsAt, string $endsAt): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest();
    createConfirmationBlockingBooking($customer, $business, $startsAt, $endsAt);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertCreated()->assertJsonPath('data.status', 'awaiting_payment');

    $this->assertDatabaseCount('bookings', 2);
    $this->assertDatabaseHas('bookings', ['booking_request_id' => $request->id, 'starts_at' => '2026-11-03 16:30:00']);
})->with([
    'ends at selected start' => ['15:00:00', '16:30:00'],
    'starts at selected end' => ['18:00:00', '19:00:00'],
]);

it('ignores cancelled expired and refunded bookings when confirming a proposed time', function (string $status): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest();
    createConfirmationBlockingBooking($customer, $business, '16:30:00', '18:00:00', $status);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertCreated();

    $this->assertDatabaseCount('bookings', 2);
})->with(['cancelled', 'expired', 'refunded']);

it('does not block confirmation because of bookings at another business', function (): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest();
    [$otherBusiness] = createBookableBusinessWithService();
    createConfirmationBlockingBooking($customer, $otherBusiness, '16:30:00', '18:00:00');

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertCreated();

    $this->assertDatabaseHas('bookings', ['booking_request_id' => $request->id, 'business_id' => $business->id]);
});

it('rechecks changed opening hours before confirming a proposal', function (string $opensAt, string $closesAt): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest();
    $business->hours()->update(['opens_at' => $opensAt, 'closes_at' => $closesAt]);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT')
        ->assertJsonPath('message', 'The selected time is outside the business opening hours. Pick another time.');

    $this->assertDatabaseCount('bookings', 0);
    $this->assertDatabaseHas('booking_requests', ['id' => $request->id, 'status' => 'pending']);
})->with([
    'starts before opening' => ['17:00', '20:00'],
    'ends after closing' => ['09:00', '17:30'],
]);

it('allows a proposed interval ending exactly at closing', function (): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest();
    $business->hours()->update(['closes_at' => '18:00']);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertCreated();

    $this->assertDatabaseHas('bookings', ['booking_request_id' => $request->id, 'ends_at' => '2026-11-03 18:00:00']);
});

it('rejects confirmation after the business adds a closure', function (): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest();
    $business->closures()->create(['starts_on' => '2026-11-03', 'ends_on' => '2026-11-03']);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertConflict()->assertJsonPath('message', 'The selected time is outside the business opening hours. Pick another time.');

    $this->assertDatabaseCount('bookings', 0);
});

it('rechecks service availability and capacity before confirming a proposal', function (array $attributes, string $code): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest();
    $service->update($attributes);

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertConflict()->assertJsonPath('code', $code);

    $this->assertDatabaseCount('bookings', 0);
})->with([
    'not bookable' => [['is_bookable' => false], 'SERVICE_NOT_BOOKABLE'],
    'inactive service' => [['status' => 'inactive'], 'SERVICE_NOT_BOOKABLE'],
    'reduced capacity' => [['max_people' => 1], 'CAPACITY_EXCEEDED'],
]);

it('interprets the proposed interval in its request timezone and stores business local times', function (): void {
    [$customer, $business, $service, $request] = createProposedConfirmationRequest('UTC', 'Asia/Kathmandu');

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve', [
    ])->assertCreated();

    $this->assertDatabaseHas('bookings', [
        'booking_request_id' => $request->id,
        'starts_at' => '2026-11-03 16:30:00',
        'ends_at' => '2026-11-03 18:00:00',
        'timezone' => 'UTC',
    ]);
});
