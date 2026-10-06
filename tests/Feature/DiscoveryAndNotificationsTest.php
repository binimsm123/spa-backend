<?php

use App\Models\Notification;
use App\Models\Offer;
use App\Models\OfferRedemption;

it('returns the home catalog with categories and services', function (): void {
    [$user] = actingAsCustomer();
    createBusinessWithService();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['user', 'categories', 'services', 'unread_notifications']]);
});

it('searches businesses by name and filters empties as successful responses', function (): void {
    [$user] = actingAsCustomer();
    createBusinessWithService(['name' => 'Zen Garden Spa']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/businesses?q=zen')
        ->assertOk()
        ->assertJsonPath('success', true);

    $empty = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/businesses?q=nonexistent-term-xyz')
        ->assertOk();

    expect($empty->json('data.items'))->toBe([]);
});

it('hides non-online businesses from discovery', function (): void {
    [$user] = actingAsCustomer();
    [$business] = createBusinessWithService(['name' => 'Offline Spa']);

    $business->forceFill(['is_online' => false])->save();

    $found = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/businesses?q=offline')
        ->assertOk();

    expect(collect($found->json('data.items')))->toHaveCount(0);
});

it('validates and redeems offers once per user, then blocks duplicates', function (): void {
    [$user] = actingAsCustomer();
    [, , $service] = createBusinessWithService();

    $offer = Offer::query()->create([
        'code' => 'ONCE',
        'title' => 'One per customer',
        'discount_type' => 'fixed',
        'discount_value' => 5000,
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addDay(),
        'status' => 'active',
        'per_user_limit' => 1,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/offers/{$offer->getKey()}/redeem", ['service_id' => $service->getKey()])
        ->assertCreated()->assertJsonPath('success', true);

    // Second redemption is rejected (409), even with an idempotency key.
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/offers/{$offer->getKey()}/redeem", ['service_id' => $service->getKey()], ['Idempotency-Key' => 'other-key'])
        ->assertStatus(409);

    // Unknown code redemption is not possible by construction; expired offers are rejected.
    $expired = Offer::query()->create([
        'code' => 'OLD',
        'title' => 'Old offer',
        'discount_type' => 'fixed',
        'discount_value' => 1000,
        'starts_at' => now()->subDays(5),
        'expires_at' => now()->subDays(2),
        'status' => 'active',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/offers/{$expired->getKey()}/redeem", ['service_id' => $service->getKey()])
        ->assertStatus(409);
});

it('returns referral code and channel share payloads', function (): void {
    [$user] = actingAsCustomer();

    $me = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/referrals/me')
        ->assertOk();

    expect($me->json('data.code'))->not->toBeEmpty()
        ->and($me->json('data.share_url'))->toContain('ref=');

    $share = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/referrals/me/share', ['channel' => 'sms'])
        ->assertOk();

    expect($share->json('data.text'))->toContain($me->json('data.code'))
        ->and($share->json('data.tracking_id'))->not->toBeEmpty();
});

it('creates, filters, and toggles notification read state', function (): void {
    [$user] = actingAsCustomer();

    app(\App\Services\NotificationService::class)->notify(
        $user,
        \App\Enums\NotificationType::Booking,
        'Booking request received',
        'Your request was sent.',
        ['booking_request_id' => 'abc'],
    );

    $list = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk();

    expect($list->json('data.items'))->toHaveCount(1);

    $id = $list->json('data.items.0.id');

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/notifications/{$id}/read", ['read' => true])
        ->assertOk();

    expect($user->notifications()->whereKey($id)->first()->read_at)->not->toBeNull();

    // Another customer cannot read someone else's notification.
    [$other] = actingAsCustomer();

    $this->actingAs($other, 'sanctum')
        ->patchJson("/api/v1/notifications/{$id}/read", ['read' => true])
        ->assertStatus(403);
});
