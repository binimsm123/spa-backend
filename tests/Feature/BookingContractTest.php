<?php

use App\Models\User;

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
