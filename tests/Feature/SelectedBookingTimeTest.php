<?php

use App\Models\BookingRequest;
use App\Models\BusinessUser;
use App\Models\User;
use App\Services\BookingService;
use App\Support\ApiException;
use Illuminate\Foundation\Testing\Concerns\InteractsWithTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

function selectedTimeFixture(): array
{
    test()->travelTo(Carbon::parse('2026-11-16 08:00', 'Asia/Kathmandu'));
    [$business, $service, $price] = createBookableBusinessWithService();
    $business->update(['timezone' => 'Asia/Kathmandu']);
    $owner = bookingApprover($business);
    $customer = User::factory()->create();
    $payload = ['business_id' => $business->id, 'service_id' => $service->id, 'service_price_id' => $price->id,
        'requested_date' => '2026-11-17', 'requested_time' => '10:30', 'people_count' => 2];

    return [$business, $service, $price, $owner, $customer, $payload];
}

it('submits the chosen date and time for business approval without creating a booking or proposal times', function (): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings/requests', $payload)
        ->assertAccepted()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.requested_time', '10:30')
        ->assertJsonPath('data.timezone', 'Asia/Kathmandu')->assertJsonPath('data.booking_id', null)
        ->assertJsonMissingPath('data.available_times');
    $id = $response->json('data.booking_request_id');

    $this->assertDatabaseHas('booking_requests', ['id' => $id, 'requested_time' => '10:30:00', 'status' => 'pending']);
    $this->assertDatabaseCount('bookings', 0);
    $this->assertDatabaseCount('booking_request_times', 0);
    $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'title' => 'New booking request']);
    $this->actingAs($owner, 'sanctum')->getJson("/api/v1/business/{$business->id}/bookings/requests?status=pending")
        ->assertOk()->assertJsonPath('data.items.0.requested_time', '10:30')->assertJsonMissingPath('data.items.0.times');
});

it('lets the business approve the selected interval and exposes the resulting booking to the customer', function (): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $request = app(BookingService::class)->createRequest($customer, $payload);
    $admin = actingAsSuperadmin();
    $businessList = '/api/v1/admin/businesses?'.http_build_query(['q' => $business->name]);
    $pendingRequests = "/api/v1/admin/bookings/requests?business_id={$business->id}&status=pending";

    $this->getJson('/api/v1/admin/bookings/businesses')->assertOk()
        ->assertJsonFragment(['id' => $business->id, 'name' => $business->name]);
    $this->getJson('/api/v1/admin/bookings/businesses?'.http_build_query(['q' => strtolower($business->name)]))
        ->assertOk()->assertJsonFragment(['id' => $business->id, 'name' => $business->name]);
    $this->getJson('/api/v1/admin/bookings/businesses?q=no_matching_business')
        ->assertOk()->assertJsonCount(0, 'data.items');
    $this->getJson($businessList)->assertOk()
        ->assertJsonPath('data.items.0.pending_booking_requests_count', 1);
    $this->getJson($pendingRequests)->assertOk()
        ->assertJsonPath('data.items.0.id', $request->id);

    $response = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->id}/bookings/requests/{$request->id}/approve")
        ->assertCreated()->assertJsonPath('data.status', 'awaiting_payment')->assertJsonPath('data.appointment_time', '10:30');
    $this->assertDatabaseHas('booking_requests', ['id' => $request->id, 'status' => 'approved']);
    $this->assertDatabaseHas('bookings', ['booking_request_id' => $request->id, 'user_id' => $customer->id,
        'starts_at' => '2026-11-17 10:30:00', 'ends_at' => '2026-11-17 11:30:00', 'total_minor' => 1000000]);
    $this->assertDatabaseHas('booking_status_history', ['changed_by_user_id' => $owner->id]);
    $this->actingAs($customer, 'sanctum')->getJson("/api/v1/bookings/requests/{$request->id}")
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.booking_id', $response->json('data.booking_id'));

    $this->actingAs($admin, 'sanctum')->getJson($businessList)->assertOk()
        ->assertJsonPath('data.items.0.pending_booking_requests_count', 0);
    $this->getJson($pendingRequests)->assertOk()->assertJsonCount(0, 'data.items');
});

it('lets an authorized admin review pending booking requests', function (string $action, int $status, string $decision): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $bookingRequest = app(BookingService::class)->createRequest($customer, $payload);
    $url = "/api/v1/admin/bookings/requests/{$bookingRequest->id}";
    $body = ['action' => $action, 'reason' => 'Reviewed by support'];

    $this->actingAs($customer, 'sanctum')->patchJson($url, $body)->assertForbidden();
    actingAsSuperadmin();
    $this->patchJson($url, $body)->assertStatus($status);
    $this->assertDatabaseHas('booking_requests', ['id' => $bookingRequest->id, 'status' => $decision]);
    $this->assertDatabaseHas('audit_logs', ['action' => "booking_request.{$decision}", 'target_id' => $bookingRequest->id]);
    $this->patchJson($url, $body)->assertConflict();

    if ($action === 'reject') {
        expect($bookingRequest->fresh()->decline_reason)->toBe('Reviewed by support');
    } else {
        $this->assertDatabaseHas('bookings', ['booking_request_id' => $bookingRequest->id]);
    }
})->with([
    ['approve', 201, 'approved'],
    ['reject', 200, 'rejected'],
]);

it('lets the business reject the selected interval and records the reason', function (): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $request = app(BookingService::class)->createRequest($customer, $payload);

    $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->id}/bookings/requests/{$request->id}/reject", ['reason' => 'Please choose another time.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Please choose another time.');
    $this->assertDatabaseCount('bookings', 0);
    $this->actingAs($customer, 'sanctum')->getJson("/api/v1/bookings/requests/{$request->id}")
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Please choose another time.');
});

it('requires an HH:mm selected time', function (mixed $time, string $message): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $payload['requested_time'] = $time;

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings/requests', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors(['requested_time' => $message]);
    $this->assertDatabaseCount('booking_requests', 0);
})->with([
    'missing time' => [null, 'Please select an available start time.'],
    'seconds' => ['10:30:00', 'Please select a start time in HH:mm format.'],
    'invalid hour' => ['24:00', 'Please select a start time in HH:mm format.'],
    'off format' => ['9:00', 'Please select a start time in HH:mm format.'],
    'array' => [['10:30'], 'Please select a start time in HH:mm format.'],
]);

it('rejects an unavailable or off-grid selected time without creating any request', function (string $time): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $payload['requested_time'] = $time;

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/bookings/requests', $payload)->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT');
    $this->assertDatabaseCount('booking_requests', 0);
    $this->assertDatabaseCount('notifications', 0);
})->with(['08:00', '16:30', '10:15']);

it('allows multiple pending requests but approves only the remaining staff capacity', function (): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $bookingService = app(BookingService::class);
    $first = $bookingService->createRequest($customer, $payload);
    $second = $bookingService->createRequest(User::factory()->create(), $payload);
    $url = "/api/v1/admin/bookings/requests/{$second->id}/availability";
    $this->actingAs($customer, 'sanctum')->getJson($url)->assertForbidden();
    actingAsSuperadmin();
    $this->getJson($url)->assertOk()->assertJsonPath('data.available', true)
        ->assertJsonPath('data.pending_overlaps.0.id', $first->id)
        ->assertJsonCount(0, 'data.booking_overlaps');
    $this->assertDatabaseCount('bookings', 0);

    $booking = $bookingService->approveRequest($owner, $first);
    $this->getJson($url)->assertOk()->assertJsonPath('data.available', false)
        ->assertJsonPath('data.reason', 'There are not enough available staff for the selected time. Pick another time.')
        ->assertJsonPath('data.booking_overlaps.0.id', $booking->id)
        ->assertJsonCount(0, 'data.pending_overlaps');

    $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->id}/bookings/requests/{$second->id}/approve")
        ->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT');
    $this->assertDatabaseCount('bookings', 1);
    $this->assertDatabaseHas('booking_requests', ['id' => $second->id, 'status' => 'pending']);
});

it('restricts approval and rejection to members of the requested business', function (string $action, string $actorType): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $request = app(BookingService::class)->createRequest($customer, $payload);
    $actor = $actorType === 'customer' ? $customer : User::factory()->create();

    $this->actingAs($actor, 'sanctum')->postJson("/api/v1/business/{$business->id}/bookings/requests/{$request->id}/{$action}")->assertForbidden();
    $this->assertDatabaseHas('booking_requests', ['id' => $request->id, 'status' => 'pending']);
    $this->assertDatabaseCount('bookings', 0);
})->with(['approve', 'reject'])->with(['customer', 'unrelated user']);

it('rejects duplicate approval or rejection after a business decision', function (string $firstAction, string $nextAction): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $request = app(BookingService::class)->createRequest($customer, $payload);
    $path = "/api/v1/business/{$business->id}/bookings/requests/{$request->id}";
    $this->actingAs($owner, 'sanctum')->postJson($path.'/'.$firstAction)->assertSuccessful();

    $this->postJson($path.'/'.$nextAction)->assertConflict();
    $this->assertDatabaseCount('bookings', $firstAction === 'approve' ? 1 : 0);
})->with([['approve', 'approve'], ['approve', 'reject'], ['reject', 'approve'], ['reject', 'reject']]);

it('removes the customer confirmation and business proposal and decline APIs', function (): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $request = app(BookingService::class)->createRequest($customer, $payload);
    $this->actingAs($customer, 'sanctum')->postJson("/api/v1/bookings/requests/{$request->id}/confirm")->assertNotFound();
    $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->id}/bookings/requests/{$request->id}/propose")->assertNotFound();
    $this->postJson("/api/v1/business/{$business->id}/bookings/requests/{$request->id}/decline")->assertNotFound();
});

it('rejects expired approval and rejection without changing the request', function (string $action): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $request = app(BookingService::class)->createRequest($customer, $payload);
    $request->update(['expires_at' => now()->subSecond()]);

    $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->id}/bookings/requests/{$request->id}/{$action}")
        ->assertConflict()->assertJsonPath('code', 'BOOKING_REQUEST_EXPIRED');
    $this->assertDatabaseHas('booking_requests', ['id' => $request->id, 'status' => 'pending']);
    $this->assertDatabaseCount('bookings', 0);
})->with(['approve', 'reject']);

it('blocks decisions by inactive business members', function (string $action): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $request = app(BookingService::class)->createRequest($customer, $payload);
    BusinessUser::query()->where('business_id', $business->id)->where('user_id', $owner->id)->update(['is_active' => false]);

    $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->id}/bookings/requests/{$request->id}/{$action}")->assertForbidden();
    $this->assertDatabaseCount('bookings', 0);
})->with(['approve', 'reject']);

it('allows active business staff to decide a pending request', function (string $role): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $actor = User::factory()->create();
    BusinessUser::query()->create(['business_id' => $business->id, 'user_id' => $actor->id, 'role' => $role, 'is_active' => true]);
    $request = app(BookingService::class)->createRequest($customer, $payload);

    $this->actingAs($actor, 'sanctum')->postJson("/api/v1/business/{$business->id}/bookings/requests/{$request->id}/approve")->assertCreated();
    $this->assertDatabaseHas('bookings', ['booking_request_id' => $request->id, 'user_id' => $customer->id]);
})->with(['manager', 'staff']);

it('returns 404 for a request addressed under a different business even for a member of both businesses', function (string $action): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $request = app(BookingService::class)->createRequest($customer, $payload);
    [$otherBusiness] = createBusinessWithService();
    BusinessUser::query()->create(['business_id' => $otherBusiness->id, 'user_id' => $owner->id, 'role' => 'owner', 'is_active' => true]);

    $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$otherBusiness->id}/bookings/requests/{$request->id}/{$action}")->assertNotFound();
    $this->assertDatabaseCount('bookings', 0);
})->with(['approve', 'reject']);

it('creates only one selected-time request and sends notifications once when retrying with an idempotency key', function (): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $this->actingAs($customer, 'sanctum');
    $headers = ['Idempotency-Key' => 'selected-time-request'];
    $first = $this->postJson('/api/v1/bookings/requests', $payload, $headers)->assertAccepted();
    $second = $this->postJson('/api/v1/bookings/requests', $payload, $headers)->assertAccepted();

    expect($second->json('data.booking_request_id'))->toBe($first->json('data.booking_request_id'));
    $this->assertDatabaseCount('booking_requests', 1);
    $this->assertDatabaseCount('notifications', 2);
    $payload['requested_time'] = '11:00';
    $this->postJson('/api/v1/bookings/requests', $payload, $headers)->assertUnprocessable();
});

it('revalidates a selected slot from a stale availability response before submitting a request', function (): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $url = "/api/v1/businesses/{$business->id}/available-dates?service_id={$service->id}&service_price_id={$price->id}";
    $this->actingAs($customer, 'sanctum')->getJson($url)->assertOk();
    $service->staffMemberships()->firstOrFail()->hours()->update(['is_closed' => true]);

    $this->postJson('/api/v1/bookings/requests', $payload)->assertConflict()->assertJsonPath('code', 'BOOKING_SLOT_CONFLICT');
    $this->assertDatabaseCount('booking_requests', 0);
});

it('serializes concurrent business approvals competing for the same staff capacity', function (): void {
    [$business, $service, $price, $owner, $customer, $payload] = selectedTimeFixture();
    $secondCustomer = User::factory()->create();
    $first = app(BookingService::class)->createRequest($customer, $payload);
    $second = app(BookingService::class)->createRequest($secondCustomer, $payload);
    $ownerId = $owner->id;
    $businessId = $business->id;
    $userIds = $business->members()->pluck('users.id')->push($customer->id)->push($secondCustomer->id)->all();
    DB::commit();

    try {
        $workers = [];
        foreach ([$first->id, $second->id] as $requestId) {
            $worker = static function () use ($ownerId, $requestId): array {
                if (config('database.connections.'.config('database.default').'.database') !== 'testing') {
                    throw new LogicException('Concurrent approval tests must use the testing database.');
                }
                $clock = new class
                {
                    use InteractsWithTime;
                };
                $clock->travelTo(Carbon::parse('2026-11-16 08:00', 'Asia/Kathmandu'));
                try {
                    $booking = app(BookingService::class)->approveRequest(User::query()->findOrFail($ownerId), BookingRequest::query()->findOrFail($requestId));

                    return ['status' => 201, 'booking_id' => $booking->id];
                } catch (ApiException $exception) {
                    return ['status' => $exception->status(), 'code' => $exception->errorCode->value];
                }
            };
            $workers[] = Closure::bind($worker, null, null);
        }
        $results = Concurrency::driver('process')->run($workers);

        expect(collect($results)->pluck('status')->sort()->values()->all())->toBe([201, 409]);
        expect(collect($results)->firstWhere('status', 409)['code'])->toBe('BOOKING_SLOT_CONFLICT');
        $this->assertDatabaseCount('bookings', 1);
    } finally {
        DB::table('businesses')->where('id', $businessId)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        DB::beginTransaction();
    }
});
