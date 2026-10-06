<?php

use App\Models\User;

it('rejects completion details for an unfinished booking using the API error envelope', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/bookings/01invalid/completion')
        ->assertNotFound();
});

it('validates completed-session reviews using the API error envelope', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/01invalid/review', [])
        ->assertNotFound();
});
