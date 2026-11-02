<?php

use App\Models\BusinessUser;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Str;

function businessListOffer(array $attributes = []): Offer
{
    return Offer::query()->create([
        'code' => 'LIST-'.Str::upper(Str::random(8)), 'title' => 'List offer',
        'discount_type' => 'percentage', 'discount_value' => 10,
        'starts_at' => now()->addDay(), 'expires_at' => now()->addWeek(),
        'status' => 'active', 'is_platform_sponsored' => false,
        ...$attributes,
    ]);
}

function businessOfferListMember(): array
{
    $owner = User::factory()->create();
    [$business, $service] = createBusinessWithService();
    BusinessUser::query()->create([
        'business_id' => $business->getKey(), 'user_id' => $owner->getKey(), 'role' => 'owner', 'is_active' => true,
    ]);
    test()->actingAs($owner, 'sanctum');

    return [$business, $service];
}

it('lists business offers and applicable platform discounts without exposing other businesses offers', function (): void {
    [$business, $service] = businessOfferListMember();
    [$otherBusiness, $otherService] = createBusinessWithService();
    $own = businessListOffer(['business_id' => $business->getKey()]);
    $platform = businessListOffer(['is_platform_sponsored' => true]);
    $servicePlatform = businessListOffer(['is_platform_sponsored' => true, 'service_id' => $service->getKey()]);
    $targetedPlatform = businessListOffer(['is_platform_sponsored' => true, 'business_id' => $business->getKey()]);
    businessListOffer(['business_id' => $otherBusiness->getKey()]);
    businessListOffer(['is_platform_sponsored' => true, 'business_id' => $otherBusiness->getKey()]);
    businessListOffer(['is_platform_sponsored' => true, 'service_id' => $otherService->getKey()]);
    businessListOffer();
    businessListOffer(['is_platform_sponsored' => true])->delete();
    businessListOffer(['business_id' => $business->getKey()])->delete();

    $response = $this->getJson('/api/v1/business/'.$business->getKey().'/offers')
        ->assertOk()->assertJsonCount(4, 'data.items');

    expect(array_column($response->json('data.items'), 'id'))->toEqualCanonicalizing([
        $own->getKey(), $platform->getKey(), $servicePlatform->getKey(), $targetedPlatform->getKey(),
    ]);
    $items = collect($response->json('data.items'))->keyBy('id');
    expect($items[$own->getKey()]['business_id'])->toBe($business->getKey());
    expect($items[$own->getKey()]['is_platform_sponsored'])->toBeFalse();
    expect($items[$platform->getKey()]['business_id'])->toBeNull();
    expect($items[$platform->getKey()]['is_platform_sponsored'])->toBeTrue();
});

it('preserves usage counters for platform discounts in the business list', function (): void {
    [$business] = businessOfferListMember();
    $offer = businessListOffer(['is_platform_sponsored' => true, 'total_usage_limit' => 5, 'usage_count' => 2]);
    $offer->redemptions()->create([
        'user_id' => User::factory()->create()->getKey(), 'idempotency_key' => (string) Str::ulid(), 'status' => 'applied',
    ]);

    $this->getJson('/api/v1/business/'.$business->getKey().'/offers')->assertOk()
        ->assertJsonPath('data.items.0.id', $offer->getKey())
        ->assertJsonPath('data.items.0.redemption_count', 1)
        ->assertJsonPath('data.items.0.remaining_usage', 3);
});

it('keeps platform offers read only for business owners', function (): void {
    [$business] = businessOfferListMember();
    $offer = businessListOffer(['is_platform_sponsored' => true]);
    $url = '/api/v1/business/'.$business->getKey().'/offers/'.$offer->getKey();

    $this->patchJson($url, ['title' => 'Changed'])->assertNotFound();
    $this->deleteJson($url)->assertNotFound();

    $this->assertNotSoftDeleted($offer);
    $this->assertDatabaseHas('offers', ['id' => $offer->getKey(), 'title' => 'List offer']);
});

it('rejects nonmembers requesting a business offer list with 403', function (): void {
    [$business] = businessOfferListMember();
    businessListOffer(['is_platform_sponsored' => true]);
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson('/api/v1/business/'.$business->getKey().'/offers')->assertForbidden();
});

it('requires authentication to list business offers with 401', function (): void {
    [$business] = createBusinessWithService();

    $this->getJson('/api/v1/business/'.$business->getKey().'/offers')->assertUnauthorized();
});
