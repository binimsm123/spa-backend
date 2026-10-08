<?php

use App\Models\User;

it('matches the collection contract for the current profile', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/users/me')
        ->assertOk()
        ->assertJsonPath('message', 'Profile loaded.')
        ->assertJsonStructure(['data' => [
            'id', 'display_name', 'email', 'mobile_number',
            'mobile_verified', 'avatar_url', 'updated_at',
        ]]);
});

it('stores address fields directly on the current user profile', function (): void {
    $user = User::factory()->create();
    $payload = [
        'address' => 'Street 12, Kamalpokhari',
        'city' => 'Kathmandu',
        'latitude' => 27.7087,
        'longitude' => 85.3222,
    ];

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/users/me', $payload)
        ->assertOk()->assertJsonPath('message', 'Profile updated.')
        ->assertJsonPath('data.address', 'Street 12, Kamalpokhari')
        ->assertJsonPath('data.city', 'Kathmandu')
        ->assertJsonPath('data.latitude', 27.7087)
        ->assertJsonPath('data.longitude', 85.3222);
    $this->assertDatabaseHas('users', ['id' => $user->getKey(), ...$payload]);

    $this->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.address', 'Street 12, Kamalpokhari')
        ->assertJsonMissingPath('data.selected_location_id');
    $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.location.address', 'Street 12, Kamalpokhari')
        ->assertJsonPath('data.location.latitude', 27.7087)->assertJsonMissingPath('data.location.id');
});

it('rejects invalid address fields without updating the user profile', function (): void {
    $user = User::factory()->create(['address' => 'Existing address']);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/users/me', [
        'address' => str_repeat('a', 256), 'city' => str_repeat('a', 121), 'latitude' => 91, 'longitude' => -181,
    ])->assertUnprocessable()->assertJsonValidationErrors(['address', 'city', 'latitude', 'longitude']);
    $this->assertDatabaseHas('users', ['id' => $user->getKey(), 'address' => 'Existing address']);
});

it('clears address fields when the user submits null values', function (): void {
    $user = User::factory()->create(['address' => 'Street 12', 'city' => 'Kathmandu', 'latitude' => 27.7, 'longitude' => 85.3]);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/users/me', [
        'address' => null, 'city' => null, 'latitude' => null, 'longitude' => null,
    ])->assertOk()->assertJsonPath('data.address', null)->assertJsonPath('data.latitude', null);
    $this->assertDatabaseHas('users', ['id' => $user->getKey(), 'address' => null, 'city' => null, 'latitude' => null, 'longitude' => null]);
});

it('updates only the authenticated users address through the location endpoint', function (): void {
    $user = User::factory()->create(['display_name' => 'Original name']);
    $other = User::factory()->create(['address' => 'Other address']);
    $payload = ['address' => 'Street 12', 'city' => 'Kathmandu', 'latitude' => 27.7087, 'longitude' => 85.3222];

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/users/me/location', [
        ...$payload, 'user_id' => $other->getKey(), 'display_name' => 'Changed name',
    ])->assertOk()->assertJsonPath('message', 'Address updated.')
        ->assertJsonPath('data.address', 'Street 12')->assertJsonPath('data.city', 'Kathmandu')
        ->assertJsonPath('data.latitude', 27.7087)->assertJsonPath('data.longitude', 85.3222);

    $this->assertDatabaseHas('users', ['id' => $user->getKey(), 'display_name' => 'Original name', ...$payload]);
    $this->assertDatabaseHas('users', ['id' => $other->getKey(), 'address' => 'Other address']);
});

it('preserves omitted address fields and clears fields submitted as null', function (): void {
    $user = User::factory()->create(['address' => 'Street 12', 'city' => 'Kathmandu', 'latitude' => 27.7, 'longitude' => 85.3]);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/users/me/location', ['address' => null, 'city' => 'Lalitpur'])
        ->assertOk()->assertJsonPath('data.address', null)->assertJsonPath('data.city', 'Lalitpur')
        ->assertJsonPath('data.latitude', 27.7)->assertJsonPath('data.longitude', 85.3);

    $this->assertDatabaseHas('users', ['id' => $user->getKey(), 'address' => null, 'city' => 'Lalitpur', 'latitude' => 27.7, 'longitude' => 85.3]);
});

it('rejects invalid address updates with 422 without changing the current user', function (): void {
    $user = User::factory()->create(['address' => 'Existing address', 'city' => 'Kathmandu', 'latitude' => 27.7, 'longitude' => 85.3]);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/users/me/location', [
        'address' => str_repeat('a', 256), 'city' => str_repeat('a', 121), 'latitude' => 91, 'longitude' => -181,
    ])->assertUnprocessable()->assertJsonValidationErrors(['address', 'city', 'latitude', 'longitude']);

    $this->assertDatabaseHas('users', ['id' => $user->getKey(), 'address' => 'Existing address', 'city' => 'Kathmandu', 'latitude' => 27.7, 'longitude' => 85.3]);
});

it('requires authentication to update the users address', function (): void {
    $this->patchJson('/api/v1/users/me/location', ['address' => 'Street 12'])->assertUnauthorized();
});
