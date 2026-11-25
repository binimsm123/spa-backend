<?php

use App\Models\User;
use Illuminate\Support\Str;

function adminBusinessOfferPayload(): array
{
    return [
        'code' => 'BUSINESS10',
        'title' => 'Business offer',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'starts_at' => now()->subDay()->toIso8601String(),
        'expires_at' => now()->addDay()->toIso8601String(),
    ];
}

it('limits an admin offer to all services of the selected business or to one service', function (bool $serviceSpecific): void {
    actingAsSuperadmin();
    [$business, $service, $price] = createBusinessWithService();
    [, $otherBusinessService] = createBusinessWithService();
    $secondService = $service->replicate()->fill(['slug' => 'second-service']);
    $secondService->save();

    $payload = adminBusinessOfferPayload() + ['business_id' => $business->id];
    if ($serviceSpecific) {
        $payload['service_id'] = $service->id;
    }

    $this->postJson('/api/v1/admin/offers', $payload)
        ->assertCreated()
        ->assertJsonPath('data.business_id', $business->id)
        ->assertJsonPath('data.service_id', $serviceSpecific ? $service->id : null)
        ->assertJsonPath('data.is_platform_sponsored', true)
        ->assertJsonPath('data.usage_count', 0)
        ->assertJsonPath('data.total_usage_limit', null)
        ->assertJsonPath('data.per_user_limit', 1);

    $this->getJson('/api/v1/admin/offers')
        ->assertOk()
        ->assertJsonPath('data.items.0.usage_count', 0)
        ->assertJsonPath('data.items.0.total_usage_limit', null)
        ->assertJsonPath('data.items.0.per_user_limit', 1);

    $expectedBusiness = ['id' => $business->id, 'name' => $business->name];
    $expectedService = $serviceSpecific ? [
        'id' => $service->id,
        'name' => $service->name,
        'service_prices' => [[
            'price_minor' => $price->price_minor,
            'currency' => $price->currency,
            'duration_minutes' => $price->durationMinutes(),
        ]],
    ] : null;

    foreach (['/api/v1/admin/offers?page=1&per_page=20', '/api/v1/admin/reports/offers', '/api/v1/offers'] as $listUrl) {
        $this->getJson($listUrl)->assertOk()
            ->assertJsonPath('data.items.0.business', $expectedBusiness)
            ->assertJsonPath('data.items.0.service', $expectedService);
    }

    $this->getJson("/api/v1/businesses/{$business->id}")->assertOk()
        ->assertJsonPath('data.offers.0.business', $expectedBusiness)
        ->assertJsonPath('data.offers.0.service', $expectedService);

    $this->actingAs(User::factory()->create(), 'sanctum');
    $url = "/api/v1/offers/{$payload['code']}/redeem";

    $this->postJson($url, ['service_id' => $otherBusinessService->id])->assertConflict();
    $this->postJson($url, ['service_id' => $secondService->id])
        ->assertStatus($serviceSpecific ? 409 : 200);

    $this->actingAs(User::factory()->create(), 'sanctum');
    $this->postJson($url, ['service_id' => $service->id])->assertOk();
})->with(['all services' => [false], 'one service' => [true]]);

it('rejects unknown businesses and services outside the selected business', function (): void {
    actingAsSuperadmin();
    [$business] = createBusinessWithService();
    [, $otherService] = createBusinessWithService();
    $url = '/api/v1/admin/offers';

    $this->postJson($url, adminBusinessOfferPayload() + ['business_id' => (string) Str::ulid()])
        ->assertUnprocessable()->assertJsonValidationErrors('business_id');

    $payload = adminBusinessOfferPayload() + ['business_id' => $business->id];
    $this->postJson($url, $payload + ['service_id' => $otherService->id])
        ->assertUnprocessable()->assertJsonValidationErrors('service_id');

    $offerId = $this->postJson($url, $payload)->assertCreated()->json('data.id');
    $this->patchJson("{$url}/{$offerId}", ['service_id' => $otherService->id])
        ->assertUnprocessable()->assertJsonValidationErrors('service_id');
});
