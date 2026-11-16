<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingService;

/** @return array{User, Business, Service, Booking, BusinessUser, BusinessUser, string} */
function bookingStaffFixture(): array
{
    [$business, $service, $first] = automaticAvailabilityFixture();
    $service->update(['required_staff_count' => 2]);
    $second = addBookingStaff($business, $service);
    $manager = User::factory()->create();
    BusinessUser::query()->create(['business_id' => $business->id, 'user_id' => $manager->id, 'role' => 'manager', 'is_active' => true]);
    $customer = User::factory()->create();
    $request = app(BookingService::class)->createRequest($customer, [
        'business_id' => $business->id, 'service_id' => $service->id, 'requested_time' => '10:00', 'requested_date' => '2026-11-14',
    ]);
    $booking = app(BookingService::class)->approveRequest(bookingApprover($business), $request);

    return [$manager, $business, $service, $booking, $first, $second, '/api/v1/business/'.$business->id.'/bookings/'.$booking->id];
}

it('suggests the required team without assigning anyone and lets a manager assign that team separately', function (): void {
    [$manager, , $service, $booking, $first, $second, $url] = bookingStaffFixture();
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'assigned_staff_user_id' => null]);
    $this->assertDatabaseCount('booking_staff', 0);
    $this->assertDatabaseHas('booking_items', ['booking_id' => $booking->id, 'required_staff_count_snapshot' => 2]);

    $response = $this->actingAs($manager, 'sanctum')->getJson($url.'/staff-suggestions')->assertOk()
        ->assertJsonPath('data.required_staff_count', 2)->assertJsonCount(2, 'data.suggested_staff');
    expect(collect($response->json('data.suggested_staff'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$first->user_id, $second->user_id])->sort()->values()->all());
    $this->assertDatabaseCount('booking_staff', 0);

    $this->putJson($url.'/staff', ['staff_user_ids' => [$first->user_id, $second->user_id]])->assertOk()
        ->assertJsonPath('data.required_staff_count', 2)->assertJsonCount(2, 'data.assigned_staff_members');
    $this->assertDatabaseHas('booking_staff', ['booking_id' => $booking->id, 'user_id' => $first->user_id]);
    $this->assertDatabaseHas('booking_staff', ['booking_id' => $booking->id, 'user_id' => $second->user_id]);

    $service->update(['required_staff_count' => 1]);
    $this->getJson($url.'/staff-suggestions')->assertOk()->assertJsonPath('data.required_staff_count', 2);
    $this->putJson($url.'/staff', ['staff_user_ids' => [$first->user_id, $second->user_id]])->assertOk();
    $this->assertDatabaseCount('booking_staff', 2);
});

it('lets a manager replace the complete assigned team atomically', function (): void {
    [$manager, $business, $service, $booking, $first, $second, $url] = bookingStaffFixture();
    $third = addBookingStaff($business, $service);
    $this->actingAs($manager, 'sanctum')->putJson($url.'/staff', ['staff_user_ids' => [$first->user_id, $second->user_id]])->assertOk();

    $this->putJson($url.'/staff', ['staff_user_ids' => [$second->user_id, $third->user_id]])->assertOk();

    $this->assertDatabaseMissing('booking_staff', ['booking_id' => $booking->id, 'user_id' => $first->user_id]);
    $this->assertDatabaseHas('booking_staff', ['booking_id' => $booking->id, 'user_id' => $second->user_id]);
    $this->assertDatabaseHas('booking_staff', ['booking_id' => $booking->id, 'user_id' => $third->user_id]);
    $this->assertDatabaseCount('booking_staff', 2);
});

it('returns 409 without changing an assignment when selected staff lack expertise or availability', function (string $reason): void {
    [$manager, $business, $service, $booking, $first, $second, $url] = bookingStaffFixture();
    $this->actingAs($manager, 'sanctum')->putJson($url.'/staff', ['staff_user_ids' => [$first->user_id, $second->user_id]])->assertOk();
    match ($reason) {
        'expertise' => $second->services()->detach(),
        'inactive membership' => $second->update(['is_active' => false]),
        'inactive user' => $second->user->update(['is_active' => false]),
        'leave' => $second->leaves()->create(['starts_on' => '2026-11-14', 'ends_on' => '2026-11-14']),
        'break' => $second->hours->each->update(['breaks' => [['starts_at' => '10:30', 'ends_at' => '11:00']]]),
        'hours' => $second->hours()->update(['opens_at' => '11:00']),
        'another booking' => automaticBooking($business, $second->user_id),
        'holiday' => $business->closures()->create(['starts_on' => '2026-11-14', 'ends_on' => '2026-11-14']),
    };

    $this->putJson($url.'/staff', ['staff_user_ids' => [$first->user_id, $second->user_id]])->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT');

    $this->assertDatabaseCount('booking_staff', 2);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'assigned_staff_user_id' => $first->user_id]);
})->with(['expertise', 'inactive membership', 'inactive user', 'leave', 'break', 'hours', 'another booking', 'holiday']);

it('returns 422 for the wrong team size or malformed assignment input', function (string $kind): void {
    [$manager, , , $booking, $first, $second, $url] = bookingStaffFixture();
    $payload = match ($kind) {
        'missing' => [],
        'empty' => ['staff_user_ids' => []],
        'too few' => ['staff_user_ids' => [$first->user_id]],
        'duplicates' => ['staff_user_ids' => [$first->user_id, $first->user_id]],
        'invalid ID' => ['staff_user_ids' => [$first->user_id, 'invalid']],
        'not a list' => ['staff_user_ids' => ['first' => $first->user_id, 'second' => $second->user_id]],
    };

    $this->actingAs($manager, 'sanctum')->putJson($url.'/staff', $payload)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');

    $this->assertDatabaseCount('booking_staff', 0);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'assigned_staff_user_id' => null]);
})->with(['missing', 'empty', 'too few', 'duplicates', 'invalid ID', 'not a list']);

it('returns 403 when an outsider inactive manager or staff member accesses staff management', function (string $role): void {
    [$manager, $business, , $booking, $first, $second, $url] = bookingStaffFixture();
    match ($role) {
        'staff' => $manager = $first->user,
        'inactive manager' => $manager->businessMemberships()->where('business_id', $business->id)->update(['is_active' => false]),
        'outsider' => $manager = User::factory()->create(),
    };

    $this->actingAs($manager, 'sanctum')->getJson($url.'/staff-suggestions')->assertForbidden();
    $this->putJson($url.'/staff', ['staff_user_ids' => [$first->user_id, $second->user_id]])->assertForbidden();
    $this->assertDatabaseCount('booking_staff', 0);
})->with(['staff', 'inactive manager', 'outsider']);

it('returns 401 without authentication and 404 for a booking from another business', function (): void {
    [$manager, $business, , $booking, $first, $second, $url] = bookingStaffFixture();
    auth()->forgetGuards();
    $this->getJson($url.'/staff-suggestions')->assertUnauthorized();
    $this->putJson($url.'/staff', ['staff_user_ids' => [$first->user_id, $second->user_id]])->assertUnauthorized();
    [$other] = createBusinessWithService();
    $booking->update(['business_id' => $other->id]);

    $this->actingAs($manager, 'sanctum')->getJson($url.'/staff-suggestions')->assertNotFound();
    $this->putJson($url.'/staff', ['staff_user_ids' => [$first->user_id, $second->user_id]])->assertNotFound();
    $this->assertDatabaseCount('booking_staff', 0);
});

it('returns 409 when assigning staff to an inactive booking', function (string $status): void {
    [$manager, , , $booking, $first, $second, $url] = bookingStaffFixture();
    $booking->update(['status' => $status]);

    $this->actingAs($manager, 'sanctum')->putJson($url.'/staff', ['staff_user_ids' => [$first->user_id, $second->user_id]])
        ->assertConflict()->assertJsonPath('code', 'BOOKING_STATUS_TRANSITION_INVALID');
    $this->assertDatabaseCount('booking_staff', 0);
})->with(['completed', 'cancelled', 'expired', 'refunded']);

it('preserves scarce expertise for an overlapping unassigned booking when suggesting and assigning staff', function (): void {
    [$business, $service, $first] = automaticAvailabilityFixture();
    $second = addBookingStaff($business, $service);
    $specialty = $business->services()->create(['name' => 'Specialist treatment', 'slug' => 'specialty', 'required_staff_count' => 1]);
    $specialty->prices()->create(['duration' => '01:00', 'price_minor' => 500000, 'currency' => 'NPR', 'is_current' => true]);
    $second->services()->attach($specialty);
    $customer = User::factory()->create();
    $bookings = [];
    foreach ([$service, $specialty] as $selectedService) {
        $request = app(BookingService::class)->createRequest($customer, ['business_id' => $business->id, 'service_id' => $selectedService->id, 'requested_time' => '10:00', 'requested_date' => '2026-11-14']);
        $bookings[] = app(BookingService::class)->approveRequest(bookingApprover($business), $request);
    }
    $manager = User::factory()->create();
    BusinessUser::query()->create(['business_id' => $business->id, 'user_id' => $manager->id, 'role' => 'manager', 'is_active' => true]);
    $url = '/api/v1/business/'.$business->id.'/bookings/'.$bookings[0]->id;

    $this->actingAs($manager, 'sanctum')->getJson($url.'/staff-suggestions')->assertOk()
        ->assertJsonCount(1, 'data.suggested_staff')->assertJsonPath('data.suggested_staff.0.id', $first->user_id);
    $this->putJson($url.'/staff', ['staff_user_ids' => [$second->user_id]])->assertConflict();
    $this->putJson($url.'/staff', ['staff_user_ids' => [$first->user_id]])->assertOk();

    $this->assertDatabaseHas('booking_staff', ['booking_id' => $bookings[0]->id, 'user_id' => $first->user_id]);
    $this->assertDatabaseHas('bookings', ['id' => $bookings[1]->id, 'assigned_staff_user_id' => null]);
});

it('keeps assignment separate from booking status updates', function (): void {
    [$manager, , , $booking, $first, $second, $url] = bookingStaffFixture();

    $this->actingAs($manager, 'sanctum')->patchJson($url.'/status', ['status' => 'confirmed', 'staff_user_id' => $first->user_id])
        ->assertUnprocessable()->assertJsonValidationErrors('staff_user_id');

    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'awaiting_payment', 'assigned_staff_user_id' => null]);
    $this->assertDatabaseCount('booking_staff', 0);
});

it('uses the same staffing checks for admin reassignment and filters bookings by every assigned staff member', function (): void {
    [, , , $booking, $first, $second] = bookingStaffFixture();
    actingAsSuperadmin();

    $this->patchJson('/api/v1/admin/bookings/'.$booking->id.'/staff', ['staff_user_ids' => [$first->user_id, $second->user_id]])
        ->assertOk()->assertJsonCount(2, 'data.assigned_staff_members');
    $this->getJson('/api/v1/admin/bookings?staff_user_id='.$second->user_id)->assertOk()
        ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $booking->id);

    $this->assertDatabaseHas('booking_staff', ['booking_id' => $booking->id, 'user_id' => $second->user_id]);
});
