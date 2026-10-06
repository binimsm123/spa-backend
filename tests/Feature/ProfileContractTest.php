<?php

use App\Models\Location;
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

it('matches the collection contract for selecting a location', function (): void {
    $user = User::factory()->create();
    $location = Location::query()->create([
        'region' => 'kathmandu-valley',
        'name' => 'Kamalpokhari',
        'city' => 'Kathmandu',
        'slug' => 'kamal pokhari-'.uniqid(),
        'latitude' => 27.7087,
        'longitude' => 85.3222,
        'is_serviceable' => true,
    ]);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/users/me/location', ['location_id' => $location->getKey()])
        ->assertOk()
        ->assertJsonPath('message', 'Location updated.')
        ->assertJsonStructure(['data' => [
            'user_id', 'location' => ['id', 'name', 'city', 'available'], 'updated_at',
        ]]);
});
