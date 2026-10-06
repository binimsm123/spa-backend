<?php

use App\Models\User;

it('matches the collection contract for the home catalog', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/home?latitude=27.7087&longitude=85.3222&timezone=Asia/Kathmandu')
        ->assertOk()
        ->assertJsonPath('message', 'Home catalog loaded.')
        ->assertJsonStructure(['data' => [
            'user' => ['id', 'display_name', 'mobile_number', 'avatar_url'],
            'unread_notification_count', 'location', 'banner',
            'categories',
        ]]);
});

it('matches the collection contract for supported locations', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/locations?region=kathmandu-valley&include_unavailable=false')
        ->assertOk()
        ->assertJsonPath('message', 'Locations loaded.')
        ->assertJsonStructure(['data' => [
            'selected_location_id', 'region', 'locations',
        ]]);
});

it('matches the collection contract for offers', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/offers?status=active&page=1&per_page=20')
        ->assertOk()
        ->assertJsonPath('message', 'Offers loaded.')
        ->assertJsonStructure(['data' => [
            'items', 'pagination' => ['page', 'per_page', 'total', 'total_pages'],
        ]]);
});
