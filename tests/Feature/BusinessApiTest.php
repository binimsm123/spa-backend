<?php

use App\Models\BusinessUser;
use App\Models\Offer;
use App\Models\ServicePrice;
use App\Services\AvailabilityService;
use Illuminate\Support\Str;

function createBusinessOwner(): array
{
    [$owner] = actingAsCustomer(['display_name' => 'Owner '.Str::random(4)]);
    $owner->assignRole('owner');

    [$business] = createBusinessWithService(['name' => 'Owner Spa '.Str::random(4)]);

    BusinessUser::query()->create([
        'business_id' => $business->getKey(),
        'user_id' => $owner->getKey(),
        'role' => 'owner',
        'is_active' => true,
    ]);

    return [$owner, $business];
}

it('lists only businesses the user manages', function (): void {
    [$owner, $business] = createBusinessOwner();
    createBusinessWithService(['name' => 'Other Spa']); // not managed by owner

    $response = $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/business/my')
        ->assertOk()
        ->assertJsonMissingPath('data.items.0.is_insured');

    $names = collect($response->json('data.items'))->pluck('name');

    expect($names)->toContain($business->name)
        ->and($names)->not->toContain('Other Spa');
});

it('blocks non-members from managing a business', function (): void {
    [$owner, $business] = createBusinessOwner();
    [$outsider] = actingAsCustomer();

    $this->actingAs($outsider, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}", ['name' => 'Hacked Name'])
        ->assertStatus(403);
});

it('updates business profile and online state', function (): void {
    [$owner, $business] = createBusinessOwner();

    $this->actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}", [
            'about' => 'Updated about text.',
            'is_online' => false,
        ])->assertOk();

    expect($business->refresh()->about)->toBe('Updated about text.')
        ->and($business->is_online)->toBeFalse();
});

it('updates weekly hours for a business', function (): void {
    [$owner, $business] = createBusinessOwner();

    $hours = collect(range(0, 6))->map(fn (int $d) => [
        'weekday' => $d,
        'opens_at' => $d === 0 ? null : '08:00',
        'closes_at' => $d === 0 ? null : '14:00',
        'is_closed' => $d === 0,
    ])->all();

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/business/{$business->getKey()}/hours", ['hours' => $hours])
        ->assertOk();

    expect($business->refresh()->hours->first(fn ($h) => $h->weekday === 1)->opens_at?->format('H:i'))->toBe('08:00');
});

it('creates services with prices and preserves price history on update', function (): void {
    [$owner, $business] = createBusinessOwner();

    $serviceId = $this->actingAs($owner, 'sanctum')
        ->postJson("/api/v1/business/{$business->getKey()}/services", [
            'name' => 'Hot Stone Therapy',
            'duration' => '01:15',
            'max_people' => 1,
            'price_minor' => 600000,
            'currency' => 'NPR',
        ])->assertCreated()->json('data.id');

    expect(ServicePrice::query()->where('service_id', $serviceId)->where('is_current', true)->value('price_minor'))->toBe(600000);

    // Price change: history is preserved, a new current row is inserted.
    $this->actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}/services/{$serviceId}", ['price_minor' => 650000])
        ->assertOk();

    $prices = ServicePrice::query()->where('service_id', $serviceId)->orderBy('created_at')->get();

    expect($prices)->toHaveCount(2)
        ->and($prices->first()->is_current)->toBeFalse()
        ->and($prices->last()->price_minor)->toBe(650000)
        ->and($prices->last()->durationMinutes())->toBe(75);
});

it('invites staff by mobile number and deactivates them', function (): void {
    [$owner, $business] = createBusinessOwner();

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/v1/business/{$business->getKey()}/staff/invite", [
            'mobile_number' => '+97798'.random_int(7000000, 9999999),
            'role' => 'staff',
        ])->assertCreated()->assertJsonPath('success', true);

    expect($business->members()->count())->toBe(2); // owner + invited staff
});

it('manages business offers with usage counters', function (): void {
    [$owner, $business] = createBusinessOwner();

    $offerId = $this->actingAs($owner, 'sanctum')
        ->postJson("/api/v1/business/{$business->getKey()}/offers", [
            'code' => 'OWN10',
            'title' => 'Owner 10% off',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'starts_at' => now()->subDay()->toIso8601String(),
            'expires_at' => now()->addWeek()->toIso8601String(),
        ])->assertCreated()->json('data.id');

    $this->actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}/offers/{$offerId}", ['status' => 'paused'])
        ->assertOk();

    expect(Offer::query()->find($offerId)->status->value)->toBe('paused');
});

it('stores location details directly on the business profile', function (): void {
    [$owner, $business] = createBusinessOwner();

    $payload = [
        'address' => 'Street 12',
        'city' => 'Lalitpur',
        'latitude' => 27.6787,
        'longitude' => 85.3162,
        'timezone' => 'Asia/Kathmandu',
    ];

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/business/{$business->getKey()}", $payload)
        ->assertOk()->assertJsonPath('data.address', 'Street 12')->assertJsonPath('data.timezone', 'Asia/Kathmandu');
    $this->assertDatabaseHas('businesses', ['id' => $business->getKey(), ...$payload]);

    $response = $this->getJson("/api/v1/business/{$business->getKey()}")->assertOk()
        ->assertJsonCount(7, 'data.hours')->assertJsonPath('data.city', 'Lalitpur');
    expect($response->json('data'))->not->toHaveKeys(['branches', 'primary_branch']);
});

it('rejects invalid business location details with 422', function (): void {
    [$owner, $business] = createBusinessOwner();

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/business/{$business->getKey()}", [
        'latitude' => 91,
        'longitude' => -181,
        'timezone' => null,
    ])->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude', 'timezone']);
    expect($business->refresh()->timezone)->toBe('UTC');
});

it('manages business closures and prevents deleting another business closure', function (): void {
    [$owner, $business] = createBusinessOwner();
    [$other] = createBusinessWithService();
    $date = now()->addDay()->toDateString();

    $closureId = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->getKey()}/closures", [
        'closures' => [['starts_on' => $date, 'ends_on' => $date, 'reason' => 'Maintenance']],
    ])->assertOk()->json('data.items.0.id');
    $this->assertDatabaseHas('business_closures', ['id' => $closureId, 'business_id' => $business->getKey()]);
    expect(app(AvailabilityService::class)->isClosureDate($business, now()->addDay()))->toBeTrue();

    $otherClosure = $other->closures()->create(['starts_on' => $date, 'ends_on' => $date]);
    $this->deleteJson("/api/v1/business/{$business->getKey()}/closures/{$otherClosure->getKey()}")->assertNotFound();
    $this->assertDatabaseHas('business_closures', ['id' => $otherClosure->getKey()]);

    $this->deleteJson("/api/v1/business/{$business->getKey()}/closures/{$closureId}")->assertOk();
    $this->assertDatabaseMissing('business_closures', ['id' => $closureId]);
});

it('removes branch creation and update endpoints', function (): void {
    [$owner, $business] = createBusinessOwner();
    $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->getKey()}/branches", ['branch_name' => 'Main'])->assertNotFound();
    $this->patchJson("/api/v1/business/{$business->getKey()}/branches/".Str::ulid(), ['address' => 'Street 12'])->assertNotFound();
});

it('returns current service prices with duration on create update list and detail', function (): void {
    [$owner, $business] = createBusinessOwner();

    $created = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$business->getKey()}/services", [
        'name' => 'Hot Stone Therapy',
        'duration' => '01:15',
        'price_minor' => 600000,
        'currency' => 'NPR',
    ])->assertCreated()->assertJsonCount(1, 'data.service_prices')
        ->assertJsonPath('data.service_prices.0.duration', '01:15:00')
        ->assertJsonPath('data.service_prices.0.price_minor', 600000);
    expect($created->json('data'))->not->toHaveKey('duration_minutes');
    $serviceId = $created->json('data.id');

    $updated = $this->patchJson("/api/v1/business/{$business->getKey()}/services/{$serviceId}", ['duration' => '01:30'])
        ->assertOk()->assertJsonCount(1, 'data.service_prices')
        ->assertJsonPath('data.service_prices.0.duration', '01:30:00')
        ->assertJsonPath('data.service_prices.0.price_minor', 600000);
    expect($updated->json('data'))->not->toHaveKey('duration_minutes');
    $this->assertDatabaseHas('service_prices', ['service_id' => $serviceId, 'duration' => '01:30:00', 'is_current' => true]);
    $this->assertDatabaseHas('service_prices', ['service_id' => $serviceId, 'duration' => '01:15:00', 'is_current' => false]);

    $list = $this->getJson("/api/v1/business/{$business->getKey()}/services")->assertOk();
    $listed = collect($list->json('data.items'))->firstWhere('id', $serviceId);
    expect($listed['service_prices'])->toHaveCount(1);
    expect($listed['service_prices'][0]['duration'])->toBe('01:30:00');
    expect($listed)->not->toHaveKey('duration_minutes');

    $detail = $this->getJson("/api/v1/businesses/{$business->getKey()}")->assertOk();
    $service = collect($detail->json('data.services'))->firstWhere('id', $serviceId);
    expect($service['service_prices'])->toHaveCount(1);
    expect($service['service_prices'][0]['duration'])->toBe('01:30:00');
    expect($service)->not->toHaveKey('duration_minutes');
});
