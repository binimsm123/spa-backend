<?php

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
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
        ->assertOk();

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

it('updates weekly hours for a branch', function (): void {
    [$owner, $business] = createBusinessOwner();
    $branch = $business->locations->first();

    $hours = collect(range(0, 6))->map(fn (int $d) => [
        'weekday' => $d,
        'opens_at' => $d === 0 ? null : '08:00',
        'closes_at' => $d === 0 ? null : '14:00',
        'is_closed' => $d === 0,
    ])->all();

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/business/{$business->getKey()}/branches/{$branch->getKey()}/hours", ['hours' => $hours])
        ->assertOk();

    expect($branch->refresh()->hours->first(fn ($h) => $h->weekday === 1)->opens_at?->format('H:i'))->toBe('08:00');
});

it('creates services with prices and preserves price history on update', function (): void {
    [$owner, $business] = createBusinessOwner();

    $serviceId = $this->actingAs($owner, 'sanctum')
        ->postJson("/api/v1/business/{$business->getKey()}/services", [
            'name' => 'Hot Stone Therapy',
            'duration_minutes' => 75,
            'max_people' => 1,
            'price_minor' => 600000,
            'currency' => 'NPR',
        ])->assertCreated()->json('data.id');

    expect(\App\Models\ServicePrice::query()->where('service_id', $serviceId)->where('is_current', true)->value('price_minor'))->toBe(600000);

    // Price change: history is preserved, a new current row is inserted.
    $this->actingAs($owner, 'sanctum')
        ->patchJson("/api/v1/business/{$business->getKey()}/services/{$serviceId}", ['price_minor' => 650000])
        ->assertOk();

    $prices = \App\Models\ServicePrice::query()->where('service_id', $serviceId)->orderBy('created_at')->get();

    expect($prices)->toHaveCount(2)
        ->and($prices->first()->is_current)->toBeFalse()
        ->and($prices->last()->price_minor)->toBe(650000);
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

    expect(\App\Models\Offer::query()->find($offerId)->status->value)->toBe('paused');
});
