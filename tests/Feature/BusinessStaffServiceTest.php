<?php

use App\Models\BusinessUser;
use App\Models\Service;
use App\Models\User;

function staffExpertiseFixture(string $role = 'owner', bool $active = true): array
{
    [$business, $service] = createBusinessWithService();
    $actor = User::factory()->create();
    BusinessUser::query()->create([
        'business_id' => $business->getKey(), 'user_id' => $actor->getKey(),
        'role' => $role, 'is_active' => $active,
    ]);
    $staff = User::factory()->create();
    $membership = BusinessUser::query()->create([
        'business_id' => $business->getKey(), 'user_id' => $staff->getKey(),
        'role' => 'staff', 'is_active' => true,
    ]);

    return [$actor, $business, $staff, $membership, $service];
}

it('lets owners and managers add multiple services without removing existing expertise or creating duplicates', function (string $role): void {
    [$actor, $business, $staff, $membership, $service] = staffExpertiseFixture($role);
    $secondService = Service::query()->create([
        'business_id' => $business->getKey(), 'name' => 'Facial', 'slug' => 'facial',
    ]);
    $membership->services()->attach($service);
    $path = '/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services';

    $this->actingAs($actor, 'sanctum')->postJson($path, ['service_ids' => [strtoupper($service->getKey()), strtoupper($secondService->getKey())]])
        ->assertCreated()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.id', $secondService->getKey());
    $this->postJson($path, ['service_ids' => [strtoupper($secondService->getKey())]])->assertCreated()->assertJsonCount(2, 'data.items');

    $this->assertDatabaseCount('business_user_service', 2);
    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $service->getKey()]);
    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $secondService->getKey()]);
})->with(['owner', 'manager']);

it('replaces expertise only for the selected business membership', function (string $method): void {
    [$actor, $business, $staff, $membership, $service] = staffExpertiseFixture();
    [$otherBusiness, $otherService] = createBusinessWithService();
    $otherMembership = BusinessUser::query()->create([
        'business_id' => $otherBusiness->getKey(), 'user_id' => $staff->getKey(), 'role' => 'staff', 'is_active' => true,
    ]);
    $otherMembership->services()->attach($otherService);
    $membership->services()->attach($service);
    $replacement = Service::query()->create(['business_id' => $business->getKey(), 'name' => 'Facial', 'slug' => 'facial']);

    $this->actingAs($actor, 'sanctum')->json($method, '/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services', [
        'service_ids' => [$replacement->getKey()], 'business_user_id' => $otherMembership->getKey(),
    ])->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $replacement->getKey());

    $this->assertDatabaseMissing('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $service->getKey()]);
    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $replacement->getKey()]);
    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $otherMembership->getKey(), 'service_id' => $otherService->getKey()]);
})->with(['PUT', 'PATCH']);

it('allows clearing all expertise with an empty replacement list', function (): void {
    [$actor, $business, $staff, $membership, $service] = staffExpertiseFixture();
    $membership->services()->attach($service);

    $this->actingAs($actor, 'sanctum')->putJson('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services', [
        'service_ids' => [],
    ])->assertOk()->assertJsonPath('data.items', []);

    $this->assertDatabaseCount('business_user_service', 0);
});

it('removes only the selected staff service assignment', function (): void {
    [$actor, $business, $staff, $membership, $service] = staffExpertiseFixture('manager');
    $secondService = Service::query()->create(['business_id' => $business->getKey(), 'name' => 'Facial', 'slug' => 'facial']);
    $membership->services()->attach([$service->getKey(), $secondService->getKey()]);
    $actorMembership = $actor->businessMemberships()->firstOrFail();
    $actorMembership->services()->attach($service);

    $this->actingAs($actor, 'sanctum')->deleteJson('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services/'.$service->getKey())
        ->assertOk()->assertJsonPath('message', 'Staff service expertise removed.');

    $this->assertDatabaseMissing('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $service->getKey()]);
    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $secondService->getKey()]);
    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $actorMembership->getKey(), 'service_id' => $service->getKey()]);
    $this->assertModelExists($service);
});

it('lists expertise for business members with optional pagination and excludes deleted services', function (): void {
    [$actor, $business, $staff, $membership, $service] = staffExpertiseFixture('staff');
    $deletedService = Service::query()->create(['business_id' => $business->getKey(), 'name' => 'Deleted', 'slug' => 'deleted']);
    $secondService = Service::query()->create(['business_id' => $business->getKey(), 'name' => 'Facial', 'slug' => 'facial']);
    $membership->services()->attach([$service->getKey(), $deletedService->getKey(), $secondService->getKey()]);
    $deletedService->delete();
    $path = '/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services';

    $this->actingAs($actor, 'sanctum')->getJson($path)->assertOk()
        ->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.id', $secondService->getKey())
        ->assertJsonPath('data.items.1.id', $service->getKey());
    $this->getJson($path.'?per_page=1&page=2')->assertOk()
        ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $service->getKey())
        ->assertJsonPath('data.pagination.total', 2)->assertJsonPath('data.pagination.page', 2);
});

it('returns 401 when expertise endpoints are accessed without authentication', function (string $method): void {
    [, $business, $staff, , $service] = staffExpertiseFixture();
    $path = '/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services';

    $this->json($method, $path.($method === 'DELETE' ? '/'.$service->getKey() : ''), ['service_ids' => [$service->getKey()]])
        ->assertUnauthorized();

    $this->assertDatabaseCount('business_user_service', 0);
})->with(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);

it('returns 403 when staff members attempt to change expertise', function (string $method): void {
    [$actor, $business, $staff, $membership, $service] = staffExpertiseFixture('staff');
    $membership->services()->attach($service);
    $path = '/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services';

    $this->actingAs($actor, 'sanctum')->json($method, $path.($method === 'DELETE' ? '/'.$service->getKey() : ''), ['service_ids' => []])
        ->assertForbidden();

    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $service->getKey()]);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('returns 403 when an inactive member or outsider accesses expertise', function (string $method, bool $isMember): void {
    [$actor, $business, $staff, $membership, $service] = staffExpertiseFixture('owner', false);
    $membership->services()->attach($service);
    if (! $isMember) {
        $actor = User::factory()->create();
    }
    $path = '/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services';

    $this->actingAs($actor, 'sanctum')->json($method, $path.($method === 'DELETE' ? '/'.$service->getKey() : ''), ['service_ids' => []])
        ->assertForbidden();

    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $service->getKey()]);
})->with(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])->with([true, false]);

it('returns 404 when the staff user belongs to another business', function (string $method): void {
    [$actor, $business, , , $service] = staffExpertiseFixture();
    [, , $otherStaff] = staffExpertiseFixture();
    $path = '/api/v1/business/'.$business->getKey().'/staff/'.$otherStaff->getKey().'/services';

    $this->actingAs($actor, 'sanctum')->json($method, $path.($method === 'DELETE' ? '/'.$service->getKey() : ''), ['service_ids' => [$service->getKey()]])
        ->assertNotFound()->assertJsonPath('message', 'Staff user not found in this business.');

    $this->assertDatabaseCount('business_user_service', 0);
})->with(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);

it('returns 422 without changing assignments when any requested service belongs to another business or is deleted', function (string $method, bool $deleted): void {
    [$actor, $business, $staff, $membership, $service] = staffExpertiseFixture();
    [, $invalidService] = createBusinessWithService();
    if ($deleted) {
        $invalidService = Service::query()->create(['business_id' => $business->getKey(), 'name' => 'Deleted', 'slug' => 'deleted']);
        $invalidService->delete();
    }
    $membership->services()->attach($service);

    $this->actingAs($actor, 'sanctum')->json($method, '/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services', [
        'service_ids' => [$service->getKey(), $invalidService->getKey()],
    ])->assertUnprocessable()->assertJsonValidationErrors(['service_ids.1' => 'The selected service was not found in this business.']);

    $this->assertDatabaseCount('business_user_service', 1);
    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $service->getKey()]);
})->with(['POST', 'PUT'])->with([false, true]);

it('returns 422 for malformed expertise lists without saving assignments', function (array $payload, string $field): void {
    [$actor, $business, $staff] = staffExpertiseFixture();

    $this->actingAs($actor, 'sanctum')->postJson('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('business_user_service', 0);
})->with([
    'missing services' => [[], 'service_ids'],
    'empty additions' => [['service_ids' => []], 'service_ids'],
    'non-array' => [['service_ids' => 'invalid'], 'service_ids'],
    'non-list' => [['service_ids' => ['service' => 'invalid']], 'service_ids'],
    'invalid identifier' => [['service_ids' => ['invalid']], 'service_ids.0'],
    'unknown identifier' => [['service_ids' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV']], 'service_ids.0'],
    'null identifier' => [['service_ids' => [null]], 'service_ids.0'],
]);

it('returns 422 for duplicate services without saving assignments', function (bool $mixedCase): void {
    [$actor, $business, $staff, , $service] = staffExpertiseFixture();

    $this->actingAs($actor, 'sanctum')->postJson('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services', [
        'service_ids' => [$service->getKey(), $mixedCase ? strtoupper($service->getKey()) : $service->getKey()],
    ])->assertUnprocessable()->assertJsonValidationErrors('service_ids.0');

    $this->assertDatabaseCount('business_user_service', 0);
})->with([false, true]);

it('returns 404 when deleting an unassigned or foreign business service', function (bool $foreign): void {
    [$actor, $business, $staff] = staffExpertiseFixture();
    [, $service] = createBusinessWithService();
    if (! $foreign) {
        $service = Service::query()->create(['business_id' => $business->getKey(), 'name' => 'Facial', 'slug' => 'facial']);
    }

    $this->actingAs($actor, 'sanctum')->deleteJson('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services/'.$service->getKey())
        ->assertNotFound()->assertJsonPath('message', $foreign
            ? 'Service not found in this business.'
            : 'Service expertise is not assigned to this staff member.');

    $this->assertModelExists($service);
    $this->assertDatabaseCount('business_user_service', 0);
})->with([true, false]);

it('returns 422 when replacement omits the expertise list', function (): void {
    [$actor, $business, $staff, $membership, $service] = staffExpertiseFixture();
    $membership->services()->attach($service);

    $this->actingAs($actor, 'sanctum')->putJson('/api/v1/business/'.$business->getKey().'/staff/'.$staff->getKey().'/services', [])
        ->assertUnprocessable()->assertJsonValidationErrors('service_ids');

    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $service->getKey()]);
});

it('returns a specific 404 when deleting expertise with a missing or deleted route record', function (string $resource, string $message, bool $deleted): void {
    [$actor, $business, $staff, $membership, $service] = staffExpertiseFixture();
    $membership->services()->attach($service);
    $records = ['business' => $business, 'user' => $staff, 'service' => $service];
    $ids = ['business' => $business->getKey(), 'user' => $staff->getKey(), 'service' => $service->getKey()];

    if ($deleted) {
        $records[$resource]->delete();
    } else {
        $ids[$resource] = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    }

    $this->actingAs($actor, 'sanctum')->deleteJson('/api/v1/business/'.$ids['business'].'/staff/'.$ids['user'].'/services/'.$ids['service'])
        ->assertNotFound()->assertExactJson(['success' => false, 'message' => $message, 'code' => 'NOT_FOUND']);

    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $service->getKey()]);
})->with([
    'business' => ['business', 'Business not found.'],
    'staff user' => ['user', 'Staff user not found in this business.'],
    'service' => ['service', 'Service not found in this business.'],
])->with(['missing' => false, 'deleted' => true]);

it('returns specific 404 messages for missing business or staff across expertise endpoints', function (string $method, string $resource, string $message): void {
    [$actor, $business, $staff, $membership, $service] = staffExpertiseFixture();
    $membership->services()->attach($service);
    $businessId = $resource === 'business' ? '01ARZ3NDEKTSV4RRFFQ69G5FAV' : $business->getKey();
    $userId = $resource === 'user' ? '01ARZ3NDEKTSV4RRFFQ69G5FAV' : $staff->getKey();

    $this->actingAs($actor, 'sanctum')->json($method, '/api/v1/business/'.$businessId.'/staff/'.$userId.'/services', ['service_ids' => []])
        ->assertNotFound()->assertExactJson(['success' => false, 'message' => $message, 'code' => 'NOT_FOUND']);

    $this->assertDatabaseHas('business_user_service', ['business_user_id' => $membership->getKey(), 'service_id' => $service->getKey()]);
})->with(['GET', 'POST', 'PUT', 'PATCH'])->with([
    'business' => ['business', 'Business not found.'],
    'staff user' => ['user', 'Staff user not found in this business.'],
]);

it('preserves the existing 404 message for unrelated business routes', function (): void {
    [$actor, $business] = staffExpertiseFixture();

    $this->actingAs($actor, 'sanctum')->getJson('/api/v1/business/'.$business->getKey().'/services/01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->assertNotFound()->assertJsonPath('message', 'Resource not found.');
});
