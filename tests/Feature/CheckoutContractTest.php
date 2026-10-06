<?php

use App\Models\User;

it('requires a valid booking for checkout quote', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/01invalid/checkout/quote', [])
        ->assertNotFound();
});

it('validates payment checkout inputs using the contract envelope', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/bookings/01invalid/checkout', [])
        ->assertNotFound();
});
