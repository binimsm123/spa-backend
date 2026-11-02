<?php

use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Str;

function futureAdminOffer(array $attributes = []): Offer
{
    return Offer::query()->create([
        'created_by_user_id' => User::factory()->create()->getKey(),
        'is_platform_sponsored' => true,
        'code' => 'FUTURE-'.Str::upper(Str::random(8)),
        'title' => 'Future platform offer',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'starts_at' => now()->addDay(),
        'expires_at' => now()->addWeek(),
        'status' => 'active',
        ...$attributes,
    ]);
}

it('soft deletes an unused future admin offer and records an audit trail', function (): void {
    $this->freezeTime();
    $admin = actingAsSuperadmin();
    $created = $this->postJson('/api/v1/admin/offers', [
        'code' => 'FUTURE10', 'title' => 'Future platform offer',
        'discount_type' => 'percentage', 'discount_value' => 10,
        'starts_at' => now()->addDay()->toIso8601String(),
        'expires_at' => now()->addWeek()->toIso8601String(),
    ])->assertCreated();
    $id = $created->json('data.id');

    $this->deleteJson('/api/v1/admin/offers/'.$id)
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('message', 'Offer deleted.');

    $this->assertSoftDeleted('offers', ['id' => $id]);
    $this->assertDatabaseHas('audit_logs', [
        'actor_user_id' => $admin->getKey(), 'action' => 'offer.deleted', 'target_type' => 'offer', 'target_id' => $id,
    ]);
    $this->getJson('/api/v1/admin/offers')->assertOk()->assertJsonCount(0, 'data.items');
    $this->deleteJson('/api/v1/admin/offers/'.$id)->assertNotFound();
});

it('rejects deletion of a business offer with 409', function (): void {
    actingAsSuperadmin();
    $offer = futureAdminOffer(['is_platform_sponsored' => false]);

    $this->deleteJson('/api/v1/admin/offers/'.$offer->getKey())->assertConflict()
        ->assertJsonPath('message', 'Only admin-created offers can be deleted through this endpoint.');

    $this->assertNotSoftDeleted($offer);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'offer.deleted', 'target_id' => $offer->getKey()]);
});

it('rejects deletion once the offer start time is reached with 409', function (int $seconds): void {
    $this->freezeTime();
    actingAsSuperadmin();
    $offer = futureAdminOffer(['starts_at' => now()->addSeconds($seconds)]);

    $this->deleteJson('/api/v1/admin/offers/'.$offer->getKey())->assertConflict()
        ->assertJsonPath('message', 'Only offers with a future start date can be deleted.');

    $this->assertNotSoftDeleted($offer);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'offer.deleted', 'target_id' => $offer->getKey()]);
})->with(['already started' => [-1], 'starts now' => [0]]);

it('rejects deletion when the offer usage count is nonzero with 409', function (): void {
    actingAsSuperadmin();
    $offer = futureAdminOffer(['usage_count' => 1]);

    $this->deleteJson('/api/v1/admin/offers/'.$offer->getKey())->assertConflict()
        ->assertJsonPath('message', 'Offers with usage or redemption history cannot be deleted.');

    $this->assertNotSoftDeleted($offer);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'offer.deleted', 'target_id' => $offer->getKey()]);
});

it('rejects deletion with redemption history even when usage count is zero with 409', function (string $status): void {
    actingAsSuperadmin();
    $offer = futureAdminOffer(['usage_count' => 0]);
    $redemption = $offer->redemptions()->create([
        'user_id' => User::factory()->create()->getKey(), 'idempotency_key' => (string) Str::ulid(), 'status' => $status,
    ]);

    $this->deleteJson('/api/v1/admin/offers/'.$offer->getKey())->assertConflict()
        ->assertJsonPath('message', 'Offers with usage or redemption history cannot be deleted.');

    $this->assertNotSoftDeleted($offer);
    $this->assertModelExists($redemption);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'offer.deleted', 'target_id' => $offer->getKey()]);
})->with(['reserved', 'applied', 'released']);

it('requires authentication to delete an admin offer with 401', function (): void {
    $offer = futureAdminOffer();

    $this->deleteJson('/api/v1/admin/offers/'.$offer->getKey())->assertUnauthorized();

    $this->assertNotSoftDeleted($offer);
});

it('requires offer management permission to delete an admin offer with 403', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('admin.dashboard.view');
    $this->actingAs($user, 'sanctum');
    $offer = futureAdminOffer();

    $this->deleteJson('/api/v1/admin/offers/'.$offer->getKey())->assertForbidden();

    $this->assertNotSoftDeleted($offer);
});

it('returns 404 when deleting an unknown admin offer', function (): void {
    actingAsSuperadmin();

    $this->deleteJson('/api/v1/admin/offers/'.Str::ulid())->assertNotFound();
});
