<?php

use App\Models\User;
use App\Services\BookingService;
use Illuminate\Support\Carbon;

it('returns a clear 403 envelope when another account reads a customer booking request', function (bool $json): void {
    $this->freezeTime();
    $customer = User::factory()->create();
    $otherUser = User::factory()->create();
    [$business, $service] = createBookableBusinessWithService();
    $bookingRequest = app(BookingService::class)->createRequest($customer, [
        'business_id' => $business->getKey(),
        'service_id' => $service->getKey(),
        'requested_time' => '10:00', 'requested_date' => now()->addDay()->toDateString(),
    ]);
    $token = $otherUser->createToken('booking-request-test')->plainTextToken;
    $headers = ['Authorization' => 'Bearer '.$token];
    if ($json) {
        $headers['Accept'] = 'application/json';
    }

    $this->get('/api/v1/bookings/requests/'.$bookingRequest->getKey(), $headers)
        ->assertForbidden()
        ->assertExactJson([
            'success' => false,
            'message' => 'This booking request belongs to another customer.',
            'code' => 'FORBIDDEN',
        ]);
})->with([false, true]);

it('returns the selected interval and business approval status to the customer', function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 08:00', 'UTC'));
    $user = User::factory()->create();
    [$business, $service] = createBookableBusinessWithService();
    $owner = bookingApprover($business);
    $request = app(BookingService::class)->createRequest($user, ['business_id' => $business->id,
        'service_id' => $service->id, 'requested_date' => '2026-11-04', 'requested_time' => '10:00']);
    $url = '/api/v1/bookings/requests/'.$request->id;
    $this->actingAs($user, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.requested_time', '10:00')->assertJsonMissingPath('data.available_times');
    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/business/'.$business->id.'/bookings/requests/'.$request->id.'/approve')->assertCreated();
    $this->actingAs($user, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('data.status', 'approved');
    $this->assertDatabaseHas('bookings', ['booking_request_id' => $request->id, 'starts_at' => '2026-11-04 10:00:00']);
});

it('matches the collection contract for booking history', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/bookings?status=upcoming&page=1&per_page=20&timezone=Asia/Kathmandu')
        ->assertOk()
        ->assertJsonPath('message', 'Booking history loaded.')
        ->assertJsonStructure(['data' => [
            'items', 'pagination' => ['page', 'per_page', 'total', 'total_pages', 'has_more'],
        ]]);
});

it('returns the documented booking request validation envelope', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/requests', [])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'message', 'code', 'errors']);
});
