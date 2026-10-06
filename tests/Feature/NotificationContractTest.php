<?php

use App\Models\Notification;
use App\Models\User;

it('matches the collection contract for notification listing', function (): void {
    $user = User::factory()->create();
    Notification::query()->create(['user_id' => $user->id, 'type' => 'appointment_confirmed', 'title' => 'Appointment Confirmed', 'body' => 'Your appointment is today.', 'data' => ['booking_id' => 'bok_test']]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/notifications?period=today&page=1&per_page=20&timezone=Asia/Kathmandu')
        ->assertOk()
        ->assertJsonPath('message', 'Notifications loaded.')
        ->assertJsonStructure(['data' => ['items' => [['id', 'type', 'title', 'body', 'read', 'display_time', 'created_at', 'scheduled_for', 'data']], 'pagination' => ['page', 'per_page', 'total', 'has_more']]]);
});

it('matches the collection contract for marking notifications read', function (): void {
    $user = User::factory()->create();
    $notification = Notification::query()->create(['user_id' => $user->id, 'type' => 'appointment_confirmed', 'title' => 'Appointment Confirmed', 'body' => 'Your appointment is today.', 'data' => []]);

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('message', 'Notification updated.')
        ->assertJsonStructure(['data' => ['id', 'read', 'read_at']]);
});
