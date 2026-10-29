<?php

use App\Models\User;
use App\Services\BookingService;

it('returns a clear 403 envelope when another account reads a customer booking request', function (bool $json): void {
    $this->freezeTime();
    $customer = User::factory()->create();
    $otherUser = User::factory()->create();
    [$business, $service] = createBusinessWithService();
    $bookingRequest = app(BookingService::class)->createRequest($customer, [
        'business_id' => $business->getKey(),
        'service_id' => $service->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
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

it('returns proposed time IDs that customers can use to confirm a booking', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    [$business, $service] = createBusinessWithService();
    $bookingRequest = app(BookingService::class)->createRequest($user, [
        'business_id' => $business->getKey(),
        'service_id' => $service->getKey(),
        'requested_date' => now()->addDay()->toDateString(),
    ]);
    $url = '/api/v1/bookings/requests/'.$bookingRequest->getKey();
    $this->actingAs($user, 'sanctum');
    $this->getJson($url)->assertOk()->assertJsonPath('data.available_times', []);
    app(BookingService::class)->proposeTimes($bookingRequest, ['10:00', '14:00'], $user);
    $availableTime = $bookingRequest->times()->whereTime('starts_at', '10:00')->firstOrFail();
    $bookingRequest->times()->whereTime('starts_at', '14:00')->update(['is_available' => false]);

    $response = $this->getJson($url);

    $response->assertOk()
        ->assertJsonPath('data.status', 'times_proposed')
        ->assertJsonPath('data.available_times', [[
            'time_id' => $availableTime->getKey(),
            'starts_at' => '10:00',
        ]]);

    $this->postJson($url.'/confirm', [
        'time_id' => $response->json('data.available_times.0.time_id'),
    ])->assertCreated()->assertJsonPath('data.status', 'awaiting_payment');
    $this->assertDatabaseHas('bookings', [
        'user_id' => $user->getKey(),
        'business_id' => $business->getKey(),
        'starts_at' => now()->addDay()->format('Y-m-d').' 10:00:00',
        'status' => 'awaiting_payment',
    ]);
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
